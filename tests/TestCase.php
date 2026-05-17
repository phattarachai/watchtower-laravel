<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\WatchtowerLaravel\WatchtowerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [WatchtowerServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('watchtower.dsn', 'http://abc123@watchtower.test/42');
        $app['config']->set('watchtower.relay.enabled', true);
        $app['config']->set('watchtower.relay.path', '/api/watchtower-relay');
        $app['config']->set('watchtower.relay.async', false);
        $app['config']->set('watchtower.relay.timeout', 5);
        $app['config']->set('watchtower.forwarder.verify_ssl', false);
        $app['config']->set('watchtower.forwarder.connect_timeout', 1.0);
    }
}
