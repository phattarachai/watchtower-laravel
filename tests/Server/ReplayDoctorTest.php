<?php

declare(strict_types=1);

afterEach(function (): void {
    @unlink(resource_path('js/vendor/watchtower.js'));
    @rmdir(resource_path('js/vendor'));
    @rmdir(resource_path('js'));
});

it('reports replay as off in relay mode by default', function (): void {
    config()->set('watchtower.mode', 'relay');

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('Session Replay')
        ->expectsOutputToContain('Off — set WATCHTOWER_REPLAY_ON_ERROR_SAMPLE_RATE')
        ->assertExitCode(0);
});

it('reports the replay rates in relay mode', function (): void {
    config()->set('watchtower.mode', 'relay');
    config()->set('watchtower.replay.on_error_sample_rate', 1.0);

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('On (session 0, on-error 1)')
        ->assertExitCode(0);
});

it('warns when the published browser helper predates replay', function (): void {
    config()->set('watchtower.mode', 'relay');
    config()->set('watchtower.replay.session_sample_rate', 0.1);
    @mkdir(resource_path('js/vendor'), 0777, true);
    file_put_contents(resource_path('js/vendor/watchtower.js'), "export function initWatchtower() {}\n");

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('predates replay')
        ->assertExitCode(0);
});

it('warns that replay rates do nothing outside relay mode', function (): void {
    config()->set('watchtower.replay.on_error_sample_rate', 1.0);

    foreach (['standalone', 'dual'] as $mode) {
        config()->set('watchtower.mode', $mode);

        $this->artisan('watchtower:doctor')
            ->expectsOutputToContain('replay works in relay mode only');
    }
});

it('says nothing about replay outside relay mode while it is off', function (): void {
    $this->artisan('watchtower:doctor')
        ->doesntExpectOutputToContain('Session Replay');
});
