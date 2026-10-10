<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueUser;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

function postEnvelope(int $projectId, string $key, string $body): TestResponse
{
    return test()->call('POST', "/watchtower/api/{$projectId}/envelope", server: [
        'CONTENT_TYPE' => 'application/x-sentry-envelope',
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$key}, sentry_client=sentry.php.laravel/4.0.0",
    ], content: $body);
}

it('stores a group and an event from a posted envelope', function (): void {
    $project = makeWatchtowerProject();

    $response = postEnvelope($project->id, $project->public_key, SentryEnvelope::build());

    $response->assertSuccessful();

    $group = IssueGroup::sole();
    expect($group->project_id)->toBe($project->id)
        ->and($group->title)->toBe('RuntimeException: Something exploded')
        ->and($group->event_count)->toBe(1)
        ->and($group->status)->toBe('unresolved');

    $event = Event::sole();
    expect($event->group_id)->toBe($group->id)
        ->and($event->environment)->toBe('testing')
        ->and($event->release)->toBe('1.0.0')
        ->and($event->sdk_name)->toBe('sentry.php.laravel')
        ->and($event->payload['exception']['values'][0]['type'])->toBe('RuntimeException');
});

it('scrubs the CSRF headers, form token and XSRF cookie even under an older published config', function (): void {
    config(['watchtower.server.ingest.scrub.header_keys' => ['cookie', 'authorization', 'x-csrf-token']]);
    config(['watchtower.server.ingest.scrub.body_keys' => ['password']]);
    $project = makeWatchtowerProject();

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build(['request' => [
        'method' => 'POST',
        'headers' => ['X-XSRF-TOKEN' => 'eyJpdiI6IkxzRmZ', 'X-CSRF-TOKEN' => 'Gk3uX9fHq', 'Accept' => 'text/html'],
        'data' => ['_token' => 'Gk3uX9fHq', 'quantity' => 2],
        'cookies' => ['XSRF-TOKEN' => 'eyJpdiI6Ik'],
    ]]))->assertSuccessful();

    $request = Event::sole()->payload['request'];

    expect($request['headers']['X-XSRF-TOKEN'])->toBe('[Filtered]')
        ->and($request['headers']['X-CSRF-TOKEN'])->toBe('[Filtered]')
        ->and($request['headers']['Accept'])->toBe('text/html')
        ->and($request['data']['_token'])->toBe('[Filtered]')
        ->and($request['data']['quantity'])->toBe(2)
        ->and($request['cookies']['XSRF-TOKEN'])->toBe('[Filtered]');
});

it('stores a readable title while two values of one bug still share a group', function (): void {
    $project = makeWatchtowerProject();
    $fatal = fn (int $limit, int $tried): string => SentryEnvelope::build(['exception' => ['values' => [[
        'type' => 'Symfony\\Component\\ErrorHandler\\Error\\FatalError',
        'value' => "Allowed memory size of {$limit} bytes exhausted (tried to allocate {$tried} bytes)",
    ]]]]);

    postEnvelope($project->id, $project->public_key, $fatal(134217728, 20480))->assertSuccessful();
    postEnvelope($project->id, $project->public_key, $fatal(268435456, 65536))->assertSuccessful();

    expect(IssueGroup::sole()->title)->toBe('Symfony\\Component\\ErrorHandler\\Error\\FatalError: Allowed memory size of <N> bytes exhausted (tried to allocate <N> bytes)')
        ->and(IssueGroup::sole()->event_count)->toBe(2);
});

it('dedupes a repeated exception into the same group', function (): void {
    $project = makeWatchtowerProject();

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();
    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();

    expect(IssueGroup::count())->toBe(1)
        ->and(Event::count())->toBe(2)
        ->and(IssueGroup::sole()->event_count)->toBe(2);
});

it('counts unique users once per hashed identity', function (): void {
    $project = makeWatchtowerProject();
    $withUser = fn (string $id): string => SentryEnvelope::build(['user' => ['id' => $id]]);

    postEnvelope($project->id, $project->public_key, $withUser('7'))->assertSuccessful();
    postEnvelope($project->id, $project->public_key, $withUser('7'))->assertSuccessful();
    postEnvelope($project->id, $project->public_key, $withUser('8'))->assertSuccessful();

    expect(IssueGroup::sole()->user_count)->toBe(2)
        ->and(IssueUser::count())->toBe(2)
        ->and(Event::where('user_id_hash', hash('sha256', '7'))->count())->toBe(2);
});

it('reopens a resolved group as a regression', function (): void {
    $project = makeWatchtowerProject();

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();
    IssueGroup::sole()->update(['status' => 'resolved']);

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();

    expect(IssueGroup::sole()->status)->toBe('unresolved');
});

it('ignores non-event envelope items', function (): void {
    $project = makeWatchtowerProject();

    $body = SentryEnvelope::build([], [], [
        ['type' => 'session', 'payload' => ['sid' => 'abc']],
        ['type' => 'transaction', 'payload' => ['type' => 'transaction']],
    ]);

    postEnvelope($project->id, $project->public_key, $body)->assertSuccessful();

    expect(Event::count())->toBe(1);
});

it('accepts the key from the sentry_key query string', function (): void {
    $project = makeWatchtowerProject();

    $response = $this->call('POST', "/watchtower/api/{$project->id}/envelope?sentry_key={$project->public_key}", content: SentryEnvelope::build());

    $response->assertSuccessful();
    expect(Event::count())->toBe(1);
});

it('rejects a wrong public key with 401', function (): void {
    $project = makeWatchtowerProject();

    postEnvelope($project->id, str_repeat('f', 32), SentryEnvelope::build())
        ->assertUnauthorized()
        ->assertJsonFragment(['error' => 'invalid_key']);

    expect(Event::count())->toBe(0);
});

it('rejects a missing auth header with 401', function (): void {
    $project = makeWatchtowerProject();

    $this->call('POST', "/watchtower/api/{$project->id}/envelope", content: SentryEnvelope::build())
        ->assertUnauthorized()
        ->assertJsonFragment(['error' => 'missing_sentry_auth']);
});

it('returns 404 for an unknown project', function (): void {
    postEnvelope(9999, str_repeat('a', 32), SentryEnvelope::build())
        ->assertNotFound()
        ->assertJsonFragment(['error' => 'project_not_found']);
});

it('returns 403 for an inactive project', function (): void {
    $project = makeWatchtowerProject(['is_active' => false]);

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())
        ->assertForbidden()
        ->assertJsonFragment(['error' => 'project_inactive']);
});

it('rejects an oversized payload with 413', function (): void {
    config()->set('watchtower.server.max_payload_bytes', 128);
    $project = makeWatchtowerProject();

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())
        ->assertStatus(413)
        ->assertJsonFragment(['error' => 'payload_too_large']);

    expect(Event::count())->toBe(0);
});

it('throttles once the per-minute limit is exceeded', function (): void {
    config()->set('watchtower.server.rate_limit_per_min', 2);
    $project = makeWatchtowerProject();

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();
    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();

    $throttled = postEnvelope($project->id, $project->public_key, SentryEnvelope::build());

    $throttled->assertStatus(429)->assertJsonFragment(['error' => 'rate_limited']);
    expect($throttled->headers->get('Retry-After'))->not->toBeNull()
        ->and(Event::count())->toBe(2);
});

it('queues the process job on the configured connection and queue', function (): void {
    Queue::fake();
    config()->set('watchtower.server.queue.connection', 'redis');
    config()->set('watchtower.server.queue.name', 'watchtower-ingest');

    $project = makeWatchtowerProject();

    postEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();

    Queue::assertPushed(ProcessEventJob::class, fn (ProcessEventJob $job): bool => $job->projectId === $project->id
        && $job->connection === 'redis'
        && $job->queue === 'watchtower-ingest');
});

function postGzippedEnvelope(int $projectId, string $key, string $body): TestResponse
{
    return test()->call('POST', "/watchtower/api/{$projectId}/envelope", server: [
        'CONTENT_TYPE' => 'application/x-sentry-envelope',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$key}",
    ], content: (string) gzencode($body));
}

it('inflates a gzipped envelope', function (): void {
    $project = makeWatchtowerProject();

    postGzippedEnvelope($project->id, $project->public_key, SentryEnvelope::build())->assertSuccessful();

    expect(Event::count())->toBe(1);
});

it('refuses to inflate a gzip body past twenty times the payload cap', function (): void {
    config()->set('watchtower.server.max_payload_bytes', 10_000);
    $project = makeWatchtowerProject();
    $bomb = SentryEnvelope::build(['message' => str_repeat('a', 300_000)]);

    $response = postGzippedEnvelope($project->id, $project->public_key, $bomb);

    $response->assertSuccessful();
    expect(strlen((string) gzencode($bomb)))->toBeLessThan(10_000)
        ->and(Event::count())->toBe(0);
});
