<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Phattarachai\WatchtowerLaravel\Jobs\ForwardEnvelope;
use Phattarachai\WatchtowerLaravel\Support\EnvelopeForwarder;
use Psr\Http\Message\RequestInterface;

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function bindReplayUpstream(array $responses, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    app()->instance(Client::class, new Client(['handler' => $stack]));
    app()->forgetInstance(EnvelopeForwarder::class);
}

function postToRelayAsBrowser(string $body): TestResponse
{
    return test()->call('POST', '/api/watchtower-relay', server: ['CONTENT_TYPE' => ''], content: $body);
}

/**
 * @param  array<int, mixed>  $history
 */
function forwardedRequest(array $history): RequestInterface
{
    return $history[0]['request'];
}

beforeEach(function (): void {
    config()->set('watchtower.relay.async', true);
    Queue::fake();
});

it('forwards a replay segment synchronously even when the relay is async', function (): void {
    $history = [];
    bindReplayUpstream([new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"id":"replay"}')], $history);

    $response = postToRelayAsBrowser(replayFixture('session-seg0'));

    $response->assertOk();
    expect($response->getContent())->toBe('{"id":"replay"}')
        ->and($history)->toHaveCount(1);
    Queue::assertNotPushed(ForwardEnvelope::class);
});

it('passes the replay bytes through untouched, with no Content-Type or gzip added', function (): void {
    $history = [];
    bindReplayUpstream([new GuzzleResponse(200)], $history);
    $body = replayFixture('buffer-seg0');

    postToRelayAsBrowser($body)->assertOk();

    $sent = forwardedRequest($history);
    expect((string) $sent->getBody())->toBe($body)
        ->and($sent->hasHeader('Content-Encoding'))->toBeFalse()
        ->and($sent->hasHeader('Content-Type'))->toBeFalse();
});

it('carries a 5 MB binary segment through byte for byte', function (): void {
    $history = [];
    bindReplayUpstream([new GuzzleResponse(200)], $history);
    $recording = '{"segment_id":0}'."\n"."\x78\x9c".random_bytes(5 * 1_048_576 - 20);
    $body = '{"event_id":"'.str_repeat('e', 32).'","sent_at":"2026-11-17T08:20:32.884Z"}'."\n"
        .'{"type":"replay_event"}'."\n".'{"type":"replay_event","replay_id":"'.str_repeat('e', 32).'","segment_id":0}'."\n"
        .'{"type":"replay_recording","length":'.strlen($recording).'}'."\n".$recording;

    postToRelayAsBrowser($body)->assertOk();

    expect(hash('sha256', (string) forwardedRequest($history)->getBody()))->toBe(hash('sha256', $body));
    Queue::assertNotPushed(ForwardEnvelope::class);
});

it('hands the central answer back so the SDK stops the replay', function (): void {
    $cases = [
        'project switch off' => [429, '3600:replay:project'],
        'over budget' => [429, '60:replay:project'],
        'over the size cap' => [413, null],
    ];

    foreach ($cases as $name => [$status, $limits]) {
        $history = [];
        bindReplayUpstream([new GuzzleResponse($status, array_filter(['X-Sentry-Rate-Limits' => $limits]))], $history);

        $response = postToRelayAsBrowser(replayFixture('session-seg0'));

        expect($response->getStatusCode())->toBe($status, $name)
            ->and($response->headers->get('X-Sentry-Rate-Limits'))->toBe($limits, $name);
    }
});

it('still queues error envelopes when the relay is async', function (): void {
    $history = [];
    bindReplayUpstream([new GuzzleResponse(200)], $history);

    postToRelayAsBrowser(replayFixture('error-in-buffer-mode'))->assertStatus(202);

    expect($history)->toHaveCount(0);
    Queue::assertPushed(ForwardEnvelope::class);
});
