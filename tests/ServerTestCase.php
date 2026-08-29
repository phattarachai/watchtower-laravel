<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\McpServiceProvider;

abstract class ServerTestCase extends TestCase
{
    /**
     * Testbench does not run package discovery, so laravel/mcp's own provider —
     * which is what feeds tool arguments into `Laravel\Mcp\Request` — has to be
     * named explicitly here. A real host gets it from auto-discovery.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...(class_exists(McpServiceProvider::class) ? [McpServiceProvider::class] : []),
            ...parent::getPackageProviders($app),
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('watchtower.mode', 'standalone');
        $app['config']->set('watchtower.server.path', 'watchtower');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
    }
}
