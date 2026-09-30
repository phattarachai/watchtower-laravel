<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use Phattarachai\WatchtowerLaravel\Jobs\ForwardEnvelope;
use Phattarachai\WatchtowerLaravel\Support\EnvelopeForwarder;

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function fakeUpstream(array $responses, array &$history = []): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    app()->instance(Client::class, new Client(['handler' => $stack]));
}

function forwardJobOnAttempt(int $attempt): array
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);

    $forward = new ForwardEnvelope('http://watchtower.test/api/watchtower-relay', '{}', []);
    $forward->setJob($queueJob);

    return [$forward, $queueJob];
}

it('gzips an uncompressed envelope on the way upstream', function (): void {
    $history = [];
    fakeUpstream([new GuzzleResponse(200)], $history);
    $body = str_repeat('{"exception":"repeated"}', 200);

    app(EnvelopeForwarder::class)->forward('http://watchtower.test/api/watchtower-relay', $body, ['Content-Type' => 'application/x-sentry-envelope']);

    $sent = $history[0]['request'];

    expect($sent->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and(gzdecode((string) $sent->getBody()))->toBe($body);
});

it('passes small or already-encoded bodies through untouched', function (): void {
    $history = [];
    fakeUpstream([new GuzzleResponse(200), new GuzzleResponse(200)], $history);
    $forwarder = app(EnvelopeForwarder::class);

    $forwarder->forward('http://watchtower.test/x', '{"tiny":true}', []);
    $forwarder->forward('http://watchtower.test/x', gzencode(str_repeat('a', 5_000)), ['Content-Encoding' => 'gzip']);

    expect($history[0]['request']->getHeaderLine('Content-Encoding'))->toBe('')
        ->and((string) $history[0]['request']->getBody())->toBe('{"tiny":true}')
        ->and(gzdecode((string) $history[1]['request']->getBody()))->toBe(str_repeat('a', 5_000));
});

it('reuses one HTTP client across forwards so connections are kept alive', function (): void {
    $history = [];
    fakeUpstream([new GuzzleResponse(200), new GuzzleResponse(200)], $history);
    $forwarder = app(EnvelopeForwarder::class);

    $forwarder->forward('http://watchtower.test/x', '{}', []);
    app()->instance(Client::class, new Client);
    $forwarder->forward('http://watchtower.test/x', '{}', []);

    expect($history)->toHaveCount(2)
        ->and(app(EnvelopeForwarder::class))->toBe($forwarder);
});

it('retries an unreachable upstream with backoff', function (): void {
    fakeUpstream([new ConnectException('down', new GuzzleRequest('POST', 'http://watchtower.test'))]);
    [$forward, $queueJob] = forwardJobOnAttempt(1);
    $queueJob->shouldReceive('release')->once()->with(10);

    $forward->handle(app(EnvelopeForwarder::class));
});

it('retries an upstream 5xx', function (): void {
    fakeUpstream([new GuzzleResponse(503)]);
    [$forward, $queueJob] = forwardJobOnAttempt(2);
    $queueJob->shouldReceive('release')->once()->with(60);

    $forward->handle(app(EnvelopeForwarder::class));
});

it('drops a 429 instead of hammering a rate-limited upstream', function (): void {
    fakeUpstream([new GuzzleResponse(429)]);
    [$forward, $queueJob] = forwardJobOnAttempt(1);
    $queueJob->shouldNotReceive('release');

    $forward->handle(app(EnvelopeForwarder::class));
});

it('logs and completes on the last attempt instead of parking the body in failed_jobs', function (): void {
    Log::spy();
    fakeUpstream([new GuzzleResponse(502)]);
    [$forward, $queueJob] = forwardJobOnAttempt(3);
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldNotReceive('fail');

    $forward->handle(app(EnvelopeForwarder::class));

    Log::shouldHaveReceived('warning')->once();
});
