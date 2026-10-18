<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Phattarachai\WatchtowerCore\Alerts\Contracts\EventCounter;
use Phattarachai\WatchtowerCore\Alerts\Contracts\NotificationLog;
use Phattarachai\WatchtowerCore\Ingest\BotDetector;
use Phattarachai\WatchtowerCore\Ingest\EventNormalizer;
use Phattarachai\WatchtowerCore\Ingest\EventPipeline;
use Phattarachai\WatchtowerCore\Ingest\EventScrubber;
use Phattarachai\WatchtowerCore\Ingest\EventTruncator;
use Phattarachai\WatchtowerLaravel\Console\DoctorCommand;
use Phattarachai\WatchtowerLaravel\Console\InstallCommand;
use Phattarachai\WatchtowerLaravel\Console\ProjectCommand;
use Phattarachai\WatchtowerLaravel\Console\PruneCommand;
use Phattarachai\WatchtowerLaravel\Console\TestCommand;
use Phattarachai\WatchtowerLaravel\Http\Middleware\WatchtowerUserContext;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Phattarachai\WatchtowerLaravel\Sentry\SelfCaptureGuard;
use Phattarachai\WatchtowerLaravel\Server\Alerts\EloquentEventCounter;
use Phattarachai\WatchtowerLaravel\Server\Alerts\EloquentNotificationLog;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\AuthorizeUi;
use Phattarachai\WatchtowerLaravel\Server\Ingest\IngestThrottle;
use Phattarachai\WatchtowerLaravel\Server\Ingest\QueueBackpressure;
use Phattarachai\WatchtowerLaravel\Server\Mcp\McpRegistrar;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Phattarachai\WatchtowerLaravel\Server\Ui\UiPage;
use Phattarachai\WatchtowerLaravel\Support\EmbeddedUiPatcher;
use Phattarachai\WatchtowerLaravel\Support\EnvelopeForwarder;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;

class WatchtowerServiceProvider extends ServiceProvider
{
    /**
     * Ingest scrubs these on top of the configured lists, which a host's
     * published config freezes at install time. See BeforeSend::ALWAYS_SCRUBBED_KEYS.
     *
     * @var list<string>
     */
    private const array ALWAYS_SCRUBBED_HEADERS = ['x-csrf-token', 'x-xsrf-token'];

    /** @var list<string> */
    private const array ALWAYS_SCRUBBED_BODY_KEYS = ['_token', 'xsrf-token'];

    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/watchtower.php', 'watchtower');

        $this->app->singleton(BeforeSend::class);
        $this->app->singleton(SelfCaptureGuard::class);
        $this->app->singleton(IngestThrottle::class);
        $this->app->singleton(QueueBackpressure::class);
        $this->app->singleton(EnvelopeForwarder::class);

        $this->app->bind(EventCounter::class, EloquentEventCounter::class);
        $this->app->bind(NotificationLog::class, EloquentNotificationLog::class);

        $this->registerEventPipeline();
        $this->registerSelfCaptureTransport();
    }

    /**
     * A host's published config is frozen at publish time, and Laravel's
     * mergeConfigFrom only fills missing *top-level* keys — so a key added
     * under `server` (1.5's `server.ui.theme`) never appeared for a host whose
     * `server` block came from an older release. Running here, before
     * config:cache writes its file, puts the filled keys in the cache too.
     */
    private function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, self::fillMissingKeys(require $path, (array) $config->get($key, [])));
    }

    /**
     * Every key the host's config lacks, at any depth, is taken from the
     * defaults. A list (scrub keys, ignored exceptions, middleware) is the
     * host's as published: merging it by index would bring back entries the
     * host removed.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $published
     * @return array<array-key, mixed>
     */
    private static function fillMissingKeys(array $defaults, array $published): array
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $default;

                continue;
            }

            if (is_array($default) && ! array_is_list($default) && is_array($published[$key])) {
                $published[$key] = self::fillMissingKeys($default, $published[$key]);
            }
        }

        return $published;
    }

    /**
     * Core's pipeline, configured from `watchtower.server.*`. A plain binding,
     * not a singleton, so the objects always reflect current config.
     */
    private function registerEventPipeline(): void
    {
        $this->app->bind(EventPipeline::class, fn (): EventPipeline => new EventPipeline(
            new EventScrubber(
                headerKeys: [...(array) config('watchtower.server.ingest.scrub.header_keys', []), ...self::ALWAYS_SCRUBBED_HEADERS],
                bodyKeys: [...(array) config('watchtower.server.ingest.scrub.body_keys', []), ...self::ALWAYS_SCRUBBED_BODY_KEYS],
                placeholder: (string) config('watchtower.server.ingest.scrub.placeholder', '[Filtered]'),
                redactSqlValues: (bool) config('watchtower.server.ingest.scrub.redact_sql_values', true),
            ),
            new EventNormalizer(
                allowedEventFields: (array) config('watchtower.server.ingest.allowed_event_fields', []),
                allowedContextKeys: (array) config('watchtower.server.ingest.allowed_context_keys', []),
            ),
            new EventTruncator(
                maxEventBytes: (int) config('watchtower.server.max_event_bytes', 200_000),
                maxStringBytes: (int) config('watchtower.server.max_string_bytes', 8_192),
            ),
            botDetector: config('watchtower.server.drop_bot_events', true) ? new BotDetector : null,
        ));
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
                $this->app->make(SelfCaptureGuard::class),
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
            __DIR__.'/../resources/js/noise.js' => resource_path('js/vendor/noise.js'),
        ], 'watchtower-js');

        // Only the page needs to live in the host tree — `app.jsx`'s
        // import.meta.glob never leaves ./pages. The module itself is reached
        // through the `@watchtower` Vite alias, so there is no second copy.
        // A TypeScript host resolves only `.tsx` pages, so a `.jsx` re-publish
        // there would sit beside the stale `.tsx` and never load.
        $pageExtension = $this->app->runningInConsole() ? EmbeddedUiPatcher::pageExtension(base_path()) : 'jsx';

        $this->publishes([
            __DIR__.'/../resources/js/pages/Watchtower.jsx' => resource_path("js/pages/Watchtower.{$pageExtension}"),
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
        $this->app->make(SelfCaptureGuard::class)->subscribe($this->app['events']);
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

        if (config('watchtower.server.ui.enabled', true) && UiPage::available()) {
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
     * The embedded Inertia UI, mounted only when the host has
     * inertiajs/inertia-laravel installed. AuthorizeUi is force-appended here so it cannot
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
     * Chain our callbacks in front of whatever the user already configured in
     * config/sentry.php. Runs after the Sentry SDK provider has booted so the
     * client is built — we then mutate its Options in place.
     *
     * The self-capture guard runs first and is not switched off by
     * `before_send.enabled`: it is what stops a failing Watchtower job from
     * reporting itself back onto the queue (loopback mode goes through here;
     * the in-process transport checks the guard again).
     */
    private function registerBeforeSendChain(): void
    {
        $this->app->booted(function (): void {
            $client = SentrySdk::getCurrentHub()->getClient();

            if ($client === null) {
                return;
            }

            $options = $client->getOptions();
            $existing = $options->getBeforeSendCallback();
            $guard = $this->app->make(SelfCaptureGuard::class);
            $ours = config('watchtower.before_send.enabled') === false ? null : $this->app->make(BeforeSend::class);

            $options->setBeforeSendCallback(function ($event, $hint) use ($guard, $ours, $existing) {
                if ($guard->shouldDrop($hint?->exception)) {
                    return null;
                }

                $event = $ours === null ? $event : $ours($event, $hint);

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
