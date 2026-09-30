<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Illuminate\Foundation\Application;
use Phattarachai\WatchtowerLaravel\Tests\Support\KernelHttpClient;

/**
 * Loopback self-capture — the SDK's own HTTP transport posting back into this
 * app's ingest route — with the scrubbing BeforeSend switched off, so only the
 * self-capture guard stands between a failing job and the loop.
 */
abstract class SelfCaptureLoopbackTestCase extends SelfCaptureTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('watchtower.server.self_capture', 'loopback');
        $app['config']->set('watchtower.before_send.enabled', false);
        $app['config']->set('sentry.http_client', new KernelHttpClient);
    }
}
