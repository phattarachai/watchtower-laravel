<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Phattarachai\WatchtowerLaravel\Server\Mcp\McpRegistrar;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Phattarachai\WatchtowerLaravel\Support\Dsn;
use Phattarachai\WatchtowerLaravel\Support\EmbeddedUiPatcher;
use Sentry\Client;
use Sentry\SentrySdk;
use Throwable;

/**
 * Reports the host-app wiring the package cannot work around — tables, routes,
 * the two build-tool edits, and the delivery path events actually take — so a
 * half-finished install names what is missing instead of failing silently.
 */
final class DoctorCommand extends Command
{
    /** @var list<string> */
    private const array MODES = ['relay', 'standalone', 'dual'];

    /** @var list<string> */
    private const array TABLES = [
        'watchtower_projects',
        'watchtower_issue_groups',
        'watchtower_events',
        'watchtower_issue_users',
        'watchtower_alert_rules',
        'watchtower_notifications_sent',
    ];

    protected $signature = 'watchtower:doctor';

    protected $description = 'Check that this application satisfies the Watchtower requirements.';

    public function handle(): int
    {
        $mode = (string) config('watchtower.mode', 'relay');

        $failures = $this->check('Mode', fn (): ?string => in_array($mode, self::MODES, true)
            ? null
            : "watchtower.mode is [{$mode}] — expected one of: ".implode(', ', self::MODES).'.');

        $failures += $mode === 'relay' ? $this->relayChecks() : $this->embeddedChecks();

        $this->newLine();

        if ($failures === 0) {
            $this->components->info('Watchtower looks correctly installed.');

            return self::SUCCESS;
        }

        $this->components->error("{$failures} check(s) need attention.");

        return self::FAILURE;
    }

    private function relayChecks(): int
    {
        $failures = $this->check('Watchtower DSN', fn (): ?string => Dsn::parse(config('watchtower.dsn')) === null
            ? 'watchtower.dsn is missing or malformed — expected scheme://key@host/{numeric project id}.'
            : null);

        return $failures + $this->check('Relay route registered', fn (): ?string => config('watchtower.relay.enabled', true) === false
            ? 'watchtower.relay.enabled is false, so the browser tunnel route is not registered.'
            : (Route::has('watchtower.relay') ? null : 'Route [watchtower.relay] is missing.'));
    }

    private function embeddedChecks(): int
    {
        $failures = $this->check('Tables migrated', function (): ?string {
            $schema = Schema::connection(config('watchtower.server.connection'));

            $missing = array_values(array_filter(
                self::TABLES,
                fn (string $table): bool => ! $schema->hasTable($table),
            ));

            return $missing === [] ? null : 'Run `php artisan migrate` — missing: '.implode(', ', $missing).'.';
        });

        $failures += $this->check('Ingest route registered', fn (): ?string => Route::has('watchtower.server.ingest.envelope')
            ? null
            : 'Route [watchtower.server.ingest.envelope] is missing.');

        $failures += $this->check('UI route registered', fn (): ?string => match (true) {
            config('watchtower.server.ui.enabled', true) === false => 'watchtower.server.ui.enabled is false, so the embedded UI is not mounted.',
            ! Route::has('watchtower.ui.issues') => 'Route [watchtower.ui.issues] is missing.',
            default => null,
        });

        $failures += $this->check('Inertia page published', fn (): ?string => EmbeddedUiPatcher::publishedPagePath(base_path()) !== null
            ? null
            : 'Re-run `watchtower:install --standalone`, or publish the stub with `vendor:publish --tag=watchtower-inertia` (rename to .tsx for a TypeScript host).');

        $failures += $this->check('Vite alias @watchtower', fn (): ?string => $this->anyViteConfigHasAlias()
            ? null
            : 'Add a resolve.alias entry for @watchtower to vite.config.js — see the package README.');

        $failures += $this->checkTailwind();
        $failures += $this->check('Active project', fn (): ?string => Project::query()->where('is_active', true)->exists()
            ? null
            : 'No active project. Create one with `php artisan watchtower:project create "My App"`.');

        $this->mailAdvisory();
        $this->queueAdvisory();
        $this->mcpAdvisory();

        return $failures + $this->selfCaptureCheck();
    }

    private function checkTailwind(): int
    {
        $css = EmbeddedUiPatcher::cssFilePath(base_path());

        if (! is_file($css)) {
            return $this->check('Watchtower CSS', fn (): string => 'No resources/css/watchtower.css — re-run `watchtower:install --standalone`, or create it with the Tailwind prefix(tw) import + @source lines.');
        }

        $failures = $this->check('Tailwind prefix(tw)', fn (): ?string => EmbeddedUiPatcher::hasTailwindPrefix($css)
            ? null
            : "The module's markup needs `".EmbeddedUiPatcher::TAILWIND_IMPORT.'` in resources/css/watchtower.css.');

        return $failures + $this->check('Tailwind @source for the package', fn (): ?string => EmbeddedUiPatcher::hasTailwindSource($css)
            ? null
            : 'Add `'.EmbeddedUiPatcher::TAILWIND_SOURCE.'` to resources/css/watchtower.css, or Tailwind will emit none of the module\'s classes.');
    }

    /**
     * A misconfigured mailer only shows up when an alert fires, so it is worth
     * a row here — but it never fails the run.
     */
    private function mailAdvisory(): void
    {
        $mailer = (string) config('mail.default', 'smtp');

        if (in_array($mailer, ['array', 'log'], true)) {
            $this->advisory('Mail transport', "Mailer is [{$mailer}] — alert emails will not leave the app.", warn: true);

            return;
        }

        $this->advisory('Mail transport', "Alert emails go out over the [{$mailer}] mailer.");
    }

    private function queueAdvisory(): void
    {
        $connection = (string) (config('watchtower.server.queue.connection') ?? config('queue.default', 'sync'));

        if ($connection === 'sync') {
            $this->advisory('Queue', 'Queue is [sync] — every event is normalized inline on the request that reported it.', warn: true);

            return;
        }

        $this->advisory('Queue', "Events are processed on the [{$connection}] queue — keep a worker running.");
    }

    private function mcpAdvisory(): void
    {
        if (! McpRegistrar::available()) {
            $this->advisory('MCP server', 'laravel/mcp is not installed — `composer require laravel/mcp` to serve the embedded MCP server.', warn: true);

            return;
        }

        if (config('watchtower.server.mcp.enabled', true) === false) {
            $this->advisory('MCP server', 'watchtower.server.mcp.enabled is false — the MCP route is not registered.', warn: true);

            return;
        }

        $this->advisory('MCP server', 'Mounted at '.McpRegistrar::route().' — authenticate with a project public key.');
    }

    private function selfCaptureCheck(): int
    {
        $setting = config('watchtower.server.self_capture', 'transport');

        if ($setting === false || $setting === 'false') {
            $this->advisory('Self-capture', "Disabled — this app's own exceptions are not stored locally.", warn: true);

            return 0;
        }

        if ($setting === 'loopback') {
            $this->advisory('Self-capture', "Loopback — the SDK posts events over HTTP to this app's ingest route.");

            return 0;
        }

        return $this->check('Self-capture', fn (): ?string => match (true) {
            SentrySdk::getCurrentHub()->getClient() === null => 'No Sentry client is bound — set SENTRY_LARAVEL_DSN so the SDK initializes.',
            ! $this->transportBound() => "The Sentry client is not using Watchtower's in-process transport.",
            default => null,
        });
    }

    private function transportBound(): bool
    {
        $client = SentrySdk::getCurrentHub()->getClient();

        return $client instanceof Client && $client->getTransport() instanceof LocalTransport;
    }

    private function anyViteConfigHasAlias(): bool
    {
        $paths = EmbeddedUiPatcher::viteConfigPaths(base_path());

        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if (EmbeddedUiPatcher::hasAlias($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(): (string|null)  $check
     */
    private function check(string $label, callable $check): int
    {
        try {
            $problem = $check();
        } catch (Throwable $throwable) {
            $problem = $throwable->getMessage();
        }

        $this->components->twoColumnDetail($label, $problem === null ? '<fg=green>OK</>' : '<fg=red>FAIL</>');

        if ($problem !== null) {
            $this->line("  <fg=gray>{$problem}</>");

            return 1;
        }

        return 0;
    }

    /** A row that reports state without ever failing the run. */
    private function advisory(string $label, string $note, bool $warn = false): void
    {
        $this->components->twoColumnDetail($label, '<fg=green>OK</>');
        $this->line($warn ? "  <fg=yellow>{$note}</>" : "  <fg=gray>{$note}</>");
    }
}
