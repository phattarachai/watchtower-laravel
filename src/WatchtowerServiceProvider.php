<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Phattarachai\WatchtowerCore\Alerts\Contracts\EventCounter;
use Phattarachai\WatchtowerCore\Alerts\Contracts\NotificationLog;
use Phattarachai\WatchtowerLaravel\Console\DoctorCommand;
use Phattarachai\WatchtowerLaravel\Console\InstallCommand;
use Phattarachai\WatchtowerLaravel\Console\ProjectCommand;
use Phattarachai\WatchtowerLaravel\Console\PruneCommand;
use Phattarachai\WatchtowerLaravel\Console\TestCommand;
use Phattarachai\WatchtowerLaravel\Http\Middleware\WatchtowerUserContext;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Phattarachai\WatchtowerLaravel\Server\Alerts\EloquentEventCounter;
use Phattarachai\WatchtowerLaravel\Server\Alerts\EloquentNotificationLog;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\AuthorizeUi;
use Phattarachai\WatchtowerLaravel\Server\Mcp\McpRegistrar;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;

class WatchtowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower.php', 'watchtower');

        $this->app->singleton(BeforeSend::class);

        $this->app->bind(EventCounter::class, EloquentEventCounter::class);
        $this->app->bind(NotificationLog::class, EloquentNotificationLog::class);

        $this->registerSelfCaptureTransport();
    }

    /**
     * Swap the SDK's HTTP transport for the in-process one before the client is
     * built. `ClientBuilder` is where sentry-laravel assembles the client, so
     * extending that binding is the only seam that runs early enough — and the
     * only one that leaves every other SDK option, BeforeSend included, alone.
     *
     * The extender is always attached and decides at resolution time, because
     * `register()` is not a reliable place to read config.
     */
    private function registerSelfCaptureTransport(): void
    {
        $this->app->extend(ClientBuilder::class, function (ClientBuilder $builder): ClientBuilder {
            if (! $this->selfCaptureIsInProcess()) {
                return $builder;
            }

            return $builder->setTransport(new LocalTransport(
                new PayloadSerializer($builder->getOptions()),
                $this->app->make(EnvelopeAccepter::class),
            ));
        });
    }

    private function selfCaptureIsInProcess(): bool
    {
        return config('watchtower.mode', 'relay') !== 'relay'
            && config('watchtower.server.self_capture', 'transport') === 'transport';
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');

        $this->publishes([
            __DIR__.'/../resources/js/watchtower.js' => resource_path('js/vendor/watchtower.js'),
            __DIR__.'/../resources/js/livewire.js' => resource_path('js/vendor/livewire.js'),
        ], 'watchtower-js');

        // Only the page needs to live in the host tree — `app.jsx`'s
        // import.meta.glob never leaves ./pages. The module itself is reached
        // through the `@watchtower` Vite alias, so there is no second copy.
        $this->publishes([
            __DIR__.'/../resources/js/pages/Watchtower.jsx' => resource_path('js/pages/Watchtower.jsx'),
        ], 'watchtower-inertia');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'watchtower');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/watchtower'),
        ], 'watchtower-views');

        Blade::directive('watchtowerUser', fn (): string => "<?php echo view('watchtower::user-meta')->render(); ?>");

        if (config('watchtower.relay.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $this->registerEmbeddedServer();
        $this->registerUserContextMiddleware();
        $this->registerBeforeSendChain();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                TestCommand::class,
                DoctorCommand::class,
                ProjectCommand::class,
                PruneCommand::class,
            ]);
        }
    }

    /**
     * Standalone/dual mode owns tables and ingest routes of its own; in plain
     * relay mode nothing but the publishable migration set is registered.
     */
    private function registerEmbeddedServer(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'watchtower-migrations');

        if (config('watchtower.mode', 'relay') === 'relay') {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/server.php');
        $this->registerRetentionSchedule();

        if (McpRegistrar::enabled()) {
            McpRegistrar::register();
        }

        if (config('watchtower.server.ui.enabled', true)) {
            $this->registerUiRoutes();
        }
    }

    private function registerRetentionSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('watchtower:prune')->daily();
        });
    }

    /**
     * The embedded Inertia UI. AuthorizeUi is force-appended here so it cannot
     * be dropped by editing `watchtower.server.ui.middleware`.
     */
    private function registerUiRoutes(): void
    {
        Route::group([
            'domain' => config('watchtower.server.ui.domain'),
            'prefix' => trim((string) config('watchtower.server.path', 'watchtower'), '/'),
            'middleware' => [...(array) config('watchtower.server.ui.middleware', ['web']), AuthorizeUi::class],
            'as' => 'watchtower.ui.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/ui.php'));
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

            $options = $client->getOptions();
            $existing = $options->getBeforeSendCallback();
            $ours = $this->app->make(BeforeSend::class);

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
