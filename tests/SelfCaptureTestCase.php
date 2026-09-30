<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Sentry\Laravel\Integration;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Throwable;

/**
 * A standalone host wired the way production is: sentry-laravel booted with
 * the in-process transport, exceptions reported through the handler, and a
 * real database queue with a `database-uuids` failed-job store — the pieces the
 * self-capture feedback loop ran through.
 */
abstract class SelfCaptureTestCase extends ServerTestCase
{
    public const string PUBLIC_KEY = 'selfcapturekey0000000000000000000';

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

        $app['config']->set('sentry.dsn', 'https://'.self::PUBLIC_KEY.'@host.test/watchtower/1');
        $app['config']->set('sentry.breadcrumbs.sql_queries', false);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createQueueTables();

        $this->app->make(ExceptionHandler::class)->reportable(
            fn (Throwable $e) => Integration::captureUnhandledException($e),
        );
    }

    private function createQueueTables(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}
