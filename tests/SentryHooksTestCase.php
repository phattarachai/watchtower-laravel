<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Illuminate\Foundation\Application;
use Phattarachai\WatchtowerLaravel\Tests\Fixtures\HostSentryCallbacks;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;

/**
 * A relay host with sentry-laravel booted and its own `before_send` and
 * `before_send_transaction` in config/sentry.php, which Watchtower chains after its own.
 */
abstract class SentryHooksTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SentryServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sentry.dsn', 'http://abc123@watchtower.test/42');
        $app['config']->set('sentry.before_send', [HostSentryCallbacks::class, 'event']);
        $app['config']->set('sentry.before_send_transaction', [HostSentryCallbacks::class, 'transaction']);
        $app['config']->set('watchtower.before_send.scrub_url_patterns', ['#/feeds/\K[^/?]+#']);
    }

    protected function setUp(): void
    {
        HostSentryCallbacks::$seenUrls = [];

        parent::setUp();
    }
}
