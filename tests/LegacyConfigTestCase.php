<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Illuminate\Foundation\Application;

/**
 * A host upgraded from 1.4 without re-publishing: its config/watchtower.php is
 * the 1.4 file, so every key added since is missing from it, while the env
 * already sets one of them.
 */
abstract class LegacyConfigTestCase extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WATCHTOWER_UI_THEME');
        unset($_ENV['WATCHTOWER_UI_THEME'], $_SERVER['WATCHTOWER_UI_THEME']);

        parent::tearDown();
    }

    /**
     * The host's config files load before any provider registers, so the
     * legacy file has to be in place here — defineEnvironment() runs too late.
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        putenv('WATCHTOWER_UI_THEME=light');
        $_ENV['WATCHTOWER_UI_THEME'] = $_SERVER['WATCHTOWER_UI_THEME'] = 'light';

        $app['config']->set('watchtower', require __DIR__.'/Fixtures/config/watchtower-1.4.php');
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // The base TestCase pins relay settings; this host keeps its published file.
    }
}
