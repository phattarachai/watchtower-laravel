<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Phattarachai\WatchtowerLaravel\Console\InstallCommand;
use Phattarachai\WatchtowerLaravel\Console\TestCommand;
use Phattarachai\WatchtowerLaravel\Http\Middleware\WatchtowerUserContext;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Sentry\SentrySdk;

class WatchtowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower.php', 'watchtower');

        $this->app->singleton(BeforeSend::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');

        $this->publishes([
            __DIR__.'/../resources/js/watchtower-user-context.js' => resource_path('js/vendor/watchtower-user-context.js'),
        ], 'watchtower-js');

        if (config('watchtower.relay.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $this->registerUserContextMiddleware();
        $this->registerBeforeSendChain();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                TestCommand::class,
            ]);
        }
    }

    /**
     * Chain our BeforeSend in front of whatever the user already configured in
     * config/sentry.php. Runs after the Sentry SDK provider has booted so the
     * client is built — we then mutate its Options in place.
     */
    private function registerBeforeSendChain(): void
    {
        if (config('watchtower.before_send.enabled') === false) {
            return;
        }

        $this->app->booted(function (): void {
            $client = SentrySdk::getCurrentHub()->getClient();

            if ($client === null) {
                return;
            }

            $options  = $client->getOptions();
            $existing = $options->getBeforeSendCallback();
            $ours     = $this->app->make(BeforeSend::class);

            $options->setBeforeSendCallback(function ($event, $hint) use ($ours, $existing) {
                $event = $ours($event, $hint);

                if ($event === null) {
                    return null;
                }

                return $existing ? $existing($event, $hint) : $event;
            });
        });
    }

    private function registerUserContextMiddleware(): void
    {
        if (config('watchtower.user_context.enabled') === false) {
            return;
        }

        /** @var Router $router */
        $router = $this->app['router'];

        foreach (['web', 'api'] as $group) {
            $router->pushMiddlewareToGroup($group, WatchtowerUserContext::class);
        }
    }
}
