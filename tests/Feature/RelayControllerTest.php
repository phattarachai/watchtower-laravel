<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Queue;
use Phattarachai\WatchtowerLaravel\Jobs\ForwardEnvelope;

function bindMockGuzzle(array $responses, array &$history = []): void
{
    $mock        = new MockHandler($responses);
    $stack       = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    app()->instance(Client::class, new Client(['handler' => $stack]));
}

it('forwards envelope to upstream and returns its response', function (): void {
    $history = [];
    bindMockGuzzle([
        new GuzzleResponse(200, [
            'Content-Type'                   => 'application/json',
            'X-Sentry-Rate-Limits-Remaining' => '99',
        ], '{"id":"evt_1"}'),
    ], $history);

    $response = $this->postJson('/api/watchtower-relay', [], [
        'Content-Type' => 'application/x-sentry-envelope',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toBe('{"id":"evt_1"}');
    expect($response->headers->get('X-Sentry-Rate-Limits-Remaining'))->toBe('99');

    expect($history)->toHaveCount(1);

    /** @var GuzzleRequest $sent */
    $sent = $history[0]['request'];
    expect((string) $sent->getUri())->toBe('http://watchtower.test/api/watchtower-relay');
    expect($sent->getHeaderLine('User-Agent'))->toStartWith('WatchtowerLaravel/');
});

it('returns 502 when upstream is unreachable', function (): void {
    bindMockGuzzle([
        new ConnectException('boom', new GuzzleRequest('POST', 'http://watchtower.test')),
    ]);

    $response = $this->postJson('/api/watchtower-relay', []);

    $response->assertStatus(502);
    $response->assertJsonFragment(['error' => 'upstream_unreachable']);
});

it('returns 503 when DSN config is missing', function (): void {
    config()->set('watchtower.dsn', null);

    $response = $this->postJson('/api/watchtower-relay', []);

    $response->assertStatus(503);
    $response->assertJsonFragment(['error' => 'watchtower_dsn_missing']);
});

it('queues the forward job when async is enabled', function (): void {
    Queue::fake();
    config()->set('watchtower.relay.async', true);
    config()->set('watchtower.relay.queue', 'watchtower');

    $history = [];
    bindMockGuzzle([new GuzzleResponse(200)], $history);

    $response = $this->postJson('/api/watchtower-relay', ['probe' => true]);

    $response->assertStatus(202);
    $response->assertJsonFragment(['queued' => true]);

    Queue::assertPushed(ForwardEnvelope::class, function (ForwardEnvelope $job): bool {
        return $job->upstream === 'http://watchtower.test/api/watchtower-relay'
            && $job->queue === 'watchtower';
    });

    expect($history)->toHaveCount(0);
});
