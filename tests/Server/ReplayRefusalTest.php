<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Phattarachai\WatchtowerLaravel\Server\Ingest\IngestThrottle;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

function postEnvelopeToEmbedded(Project $project, string $body): TestResponse
{
    return test()->call('POST', "/watchtower/api/{$project->id}/envelope", server: [
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$project->public_key}, sentry_client=sentry.javascript.browser/11.1.0",
    ], content: $body);
}

function assertReplayRefused(TestResponse $response): void
{
    $response->assertStatus(429)->assertJsonFragment(['error' => 'replay_not_supported']);

    expect($response->headers->get('X-Sentry-Rate-Limits'))->toBe('3600:replay:project')
        ->and($response->headers->has('Retry-After'))->toBeFalse();
}

it('turns replay segments away for an hour without spending the event budget', function (): void {
    $project = makeWatchtowerProject();

    foreach (['session-seg0', 'buffer-seg0'] as $fixture) {
        assertReplayRefused(postEnvelopeToEmbedded($project, replayFixture($fixture)));
    }

    expect(Event::count())->toBe(0)
        ->and(RateLimiter::attempts(IngestThrottle::projectKey($project->id)))->toBe(0);
});

it('still stores the error items of an envelope that also carries replay items', function (): void {
    $project = makeWatchtowerProject();
    $body = SentryEnvelope::build([], [], [
        ['type' => 'replay_event', 'payload' => ['type' => 'replay_event', 'replay_id' => str_repeat('a', 32), 'segment_id' => 0]],
    ]);

    assertReplayRefused(postEnvelopeToEmbedded($project, $body));

    expect(Event::count())->toBe(1)
        ->and(RateLimiter::attempts(IngestThrottle::projectKey($project->id)))->toBe(1);
});

it('refuses replay segments tunnelled through the relay route in standalone mode', function (): void {
    $project = makeWatchtowerProject();
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    app()->instance(Client::class, new Client(['handler' => $stack]));

    $response = $this->call('POST', '/api/watchtower-relay', content: withEnvelopeHeader(replayFixture('session-seg0'), [
        'dsn' => $project->buildDsn('https://app.test'),
    ]));

    assertReplayRefused($response);
    expect($history)->toHaveCount(0);
});

it('keeps replay segments off the central server in dual mode', function (): void {
    config()->set('watchtower.mode', 'dual');
    $project = makeWatchtowerProject();
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    app()->instance(Client::class, new Client(['handler' => $stack]));

    $response = $this->call('POST', '/api/watchtower-relay', content: withEnvelopeHeader(replayFixture('buffer-seg0'), [
        'dsn' => $project->buildDsn('https://app.test'),
    ]));

    assertReplayRefused($response);
    expect($history)->toHaveCount(0);
});

it('answers plain error envelopes with 200 as before', function (): void {
    $project = makeWatchtowerProject();

    postEnvelopeToEmbedded($project, SentryEnvelope::build())->assertOk();

    expect(Event::count())->toBe(1);
});

it('names the error category when the event budget is spent', function (): void {
    config()->set('watchtower.server.rate_limit_per_min', 1);
    $project = makeWatchtowerProject();

    postEnvelopeToEmbedded($project, SentryEnvelope::build())->assertOk();
    $throttled = postEnvelopeToEmbedded($project, SentryEnvelope::build());

    $throttled->assertStatus(429);
    expect($throttled->headers->get('X-Sentry-Rate-Limits'))->toMatch('/^\d+:error:project$/');
});
