<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Sentry\ClientBuilder;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;

it('does not register embedded ingest routes in relay mode', function (): void {
    expect(config('watchtower.mode'))->toBe('relay')
        ->and(Route::has('watchtower.server.ingest.envelope'))->toBeFalse();

    $this->call('POST', '/watchtower/api/1/envelope', content: '{}')->assertNotFound();
});

it('does not register the embedded UI in relay mode', function (): void {
    expect(Route::has('watchtower.ui.issues'))->toBeFalse()
        ->and(Route::has('watchtower.ui.alerts'))->toBeFalse();

    $this->get('/watchtower')->assertNotFound();
});

it('does not register the MCP route in relay mode', function (): void {
    expect(Route::has('watchtower.server.mcp'))->toBeFalse();
});

it('leaves the Sentry transport alone in relay mode', function (): void {
    config()->set('sentry.dsn', 'http://abc123@watchtower.test/42');
    $this->app->register(SentryServiceProvider::class);

    expect(app(ClientBuilder::class)->getTransport())->not->toBeInstanceOf(LocalTransport::class);
});

it('leaves the Sentry transport alone when self-capture is loopback', function (): void {
    config()->set('watchtower.mode', 'standalone');
    config()->set('watchtower.server.self_capture', 'loopback');
    config()->set('sentry.dsn', 'http://abc123@watchtower.test/42');
    $this->app->register(SentryServiceProvider::class);

    expect(app(ClientBuilder::class)->getTransport())->not->toBeInstanceOf(LocalTransport::class);
});
