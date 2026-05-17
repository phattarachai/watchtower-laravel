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

    $mock  = new MockHandler([new GuzzleResponse(200, [], '{"id":"evt"}')]);
    $stack = HandlerStack::create($mock);
    app()->instance(Client::class, new Client(['handler' => $stack]));

    $this->artisan('watchtower:test')->run();

    expect(true)->toBeTrue();
});
