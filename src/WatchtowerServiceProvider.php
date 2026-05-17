<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel;

use Illuminate\Support\ServiceProvider;
use Phattarachai\WatchtowerLaravel\Console\InstallCommand;
use Phattarachai\WatchtowerLaravel\Console\TestCommand;

class WatchtowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower.php', 'watchtower');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');

        if (config('watchtower.relay.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                TestCommand::class,
            ]);
        }
    }
}
