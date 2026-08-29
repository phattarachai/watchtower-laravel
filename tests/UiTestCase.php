<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Phattarachai\WatchtowerLaravel\Tests\Fixtures\WtUser;
use Phattarachai\WatchtowerLaravel\Watchtower;
use Phattarachai\WatchtowerLaravel\WatchtowerServiceProvider;

/**
 * A minimal Laravel host for the embedded UI: the package's own tables, an
 * authenticatable, a `login` route to be redirected to, and an Inertia root
 * view. Everything the UI assumes of its host and nothing more.
 */
abstract class UiTestCase extends ServerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Watchtower::flushAuth();
        Watchtower::auth(fn ($request): bool => $request->user() !== null);
    }

    protected function tearDown(): void
    {
        Watchtower::flushAuth();

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [InertiaServiceProvider::class, WatchtowerServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];

        $config->set('app.url', 'https://apps.test');
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('auth.providers.users.model', WtUser::class);

        // The published page ships with the package, so `assertInertia`'s
        // "does this component exist?" check resolves without a host tree.
        // Inertia v3 reads `pages.*`; v2 read `testing.page_*`.
        $config->set('inertia.pages.paths', [__DIR__.'/../resources/js/pages']);
        $config->set('inertia.pages.extensions', ['jsx']);
        $config->set('inertia.testing.page_paths', [__DIR__.'/../resources/js/pages']);
        $config->set('inertia.testing.page_extensions', ['jsx']);
        $config->set('view.paths', [...(array) $config->get('view.paths', []), __DIR__.'/Fixtures/views']);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/login', fn (): string => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
