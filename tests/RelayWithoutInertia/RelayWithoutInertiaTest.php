<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Server\Ui\UiPage;

it('boots and relays without inertiajs/inertia-laravel', function (): void {
    app()->instance(Client::class, new Client([
        'handler' => HandlerStack::create(new MockHandler([new GuzzleResponse(200, [], '{"id":"evt_1"}')])),
    ]));

    expect(UiPage::available())->toBeFalse()
        ->and(Route::has('watchtower.relay'))->toBeTrue()
        ->and(Route::has('watchtower.ui.issues'))->toBeFalse();

    $this->postJson('/api/watchtower-relay', [])->assertOk();
});

it('passes the doctor in relay mode without Inertia', function (): void {
    $this->artisan('watchtower:doctor')
        ->doesntExpectOutputToContain('inertia')
        ->expectsOutputToContain('Watchtower looks correctly installed.')
        ->assertExitCode(0);
});
