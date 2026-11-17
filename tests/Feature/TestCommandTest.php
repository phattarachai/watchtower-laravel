<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Http;

it('runs to completion against a faked HTTP client', function (): void {
    Http::fake([
        '*/api/watchtower-relay' => Http::response('{"id":"evt"}', 200),
    ]);

    $mock = new MockHandler([new GuzzleResponse(200, [], '{"id":"evt"}')]);
    $stack = HandlerStack::create($mock);
    app()->instance(Client::class, new Client(['handler' => $stack]));

    $this->artisan('watchtower:test')->run();

    expect(true)->toBeTrue();
});

it('prints the replay setting', function (): void {
    Http::fake(['*/api/watchtower-relay' => Http::response('{"id":"evt"}', 200)]);
    app()->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([new GuzzleResponse(200)]))]));

    $cases = [
        'off' => ['relay', 0.0, 'Replay:     off'],
        'on' => ['relay', 0.5, 'Replay:     session 0, on-error 0.5'],
        'ignored' => ['standalone', 0.5, 'Replay:     rates set but ignored outside relay mode'],
    ];

    foreach ($cases as [$mode, $rate, $line]) {
        config()->set('watchtower.mode', $mode);
        config()->set('watchtower.replay.on_error_sample_rate', $rate);

        $this->artisan('watchtower:test')->expectsOutputToContain($line);
    }
});
