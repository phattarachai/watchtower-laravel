<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('keeps ingest but does not mount the UI without inertiajs/inertia-laravel', function (): void {
    expect(Route::has('watchtower.server.ingest.envelope'))->toBeTrue()
        ->and(Route::has('watchtower.ui.issues'))->toBeFalse();

    $this->get('/watchtower')->assertNotFound();
});

it('tells the doctor to install Inertia for the UI', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    makeWatchtowerProject();

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('composer require inertiajs/inertia-laravel')
        ->assertExitCode(1);
});

it('passes the doctor headless, with the UI switched off', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    config()->set('watchtower.server.ui.enabled', false);
    makeWatchtowerProject();

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('WATCHTOWER_UI_ENABLED=false')
        ->doesntExpectOutputToContain('Vite alias')
        ->expectsOutputToContain('Watchtower looks correctly installed.')
        ->assertExitCode(0);
});
