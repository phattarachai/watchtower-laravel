<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Watchtower;

beforeEach(function (): void {
    Route::get('/orders', fn () => Blade::render('@watchtowerUser'))->name('orders.index');
    Route::get('/checkout', fn () => Blade::render('@watchtowerUser'))->name('checkout.show');
    Route::get('/watchtower/issues', fn () => Blade::render('@watchtowerUser'))->name('watchtower.ui.issues');
});

afterEach(function (): void {
    Watchtower::flushRecordReplaysWhen();
});

function enableReplay(float $session = 0.0, float $onError = 1.0): void
{
    config()->set('watchtower.replay.session_sample_rate', $session);
    config()->set('watchtower.replay.on_error_sample_rate', $onError);
}

it('renders no replay tag while both rates are 0', function (): void {
    $this->get('/orders')->assertOk()->assertDontSee('watchtower-replay', escape: false);
});

it('renders the rates into a meta tag through @watchtowerUser', function (): void {
    enableReplay(session: 0.1, onError: 1.0);

    $html = $this->get('/orders')->assertOk()->getContent();

    expect($html)->toContain('<meta name="watchtower-user-id"')
        ->and($html)->toContain('<meta name="watchtower-replay" content="{&quot;sessionSampleRate&quot;:0.1,&quot;onErrorSampleRate&quot;:1}">');
});

it('clamps rates outside 0..1', function (): void {
    enableReplay(session: -3, onError: 7);

    expect($this->get('/orders')->getContent())
        ->toContain('{&quot;sessionSampleRate&quot;:0,&quot;onErrorSampleRate&quot;:1}');
});

it('never enables replay outside relay mode', function (): void {
    enableReplay();

    foreach (['standalone', 'dual'] as $mode) {
        config()->set('watchtower.mode', $mode);

        expect($this->get('/orders')->getContent())->not->toContain('watchtower-replay', $mode);
    }
});

it('never records the package console routes', function (): void {
    enableReplay();

    $this->get('/watchtower/issues')->assertOk()->assertDontSee('watchtower-replay', escape: false);
});

it('lets the host veto a request with recordReplaysWhen', function (): void {
    enableReplay();
    Watchtower::recordReplaysWhen(fn (Request $request): bool => ! $request->routeIs('checkout.*'));

    $this->get('/checkout')->assertDontSee('watchtower-replay', escape: false);
    $this->get('/orders')->assertSee('watchtower-replay', escape: false);
});

it('renders nothing for a Blade render outside a request route', function (): void {
    expect(Blade::render('@watchtowerUser'))->not->toContain('watchtower-replay');

    enableReplay();

    expect(Blade::render('@watchtowerUser'))->toContain('<meta name="watchtower-replay"');
});
