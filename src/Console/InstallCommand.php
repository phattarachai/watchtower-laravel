<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\WatchtowerLaravel\Support\BootstrapPatcher;
use Phattarachai\WatchtowerLaravel\Support\ClaudeMcpRegistrar;
use Phattarachai\WatchtowerLaravel\Support\Dsn;
use Phattarachai\WatchtowerLaravel\Support\EnvWriter;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    public const string PII_CONFIRM_QUESTION = 'Enable SENTRY_SEND_DEFAULT_PII (attach request data + IP — Watchtower scrubs secrets via BeforeSend)?';

    /** @var array<string, string> Breadcrumb env keys set only when absent. */
    private const array BREADCRUMB_KEYS = [
        'SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED'          => 'true',
        'SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED'         => 'false',
        'SENTRY_BREADCRUMBS_CACHE_ENABLED'                => 'true',
        'SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED' => 'true',
        'SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED'       => 'true',
    ];

    protected $signature = 'watchtower:install
        {--dsn= : Watchtower DSN, e.g. http://key@host/42}
        {--dry-run : Print intended changes without writing files}
        {--no-mcp : Skip registering the Watchtower MCP server with Claude Code}';

    protected $description = 'Wire up Watchtower error tracking: DSN, exception handler, frontend tunnel, Claude MCP.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dsn    = $this->resolveDsn();

        if ($dsn === null) {
            return self::FAILURE;
        }

        $this->writeEnvKeys($dsn, $dryRun);
        $this->confirmPii($dryRun);
        $this->writeBreadcrumbEnvKeys($dryRun);
        $this->patchBootstrap($dryRun);

        if (! $dryRun) {
            $this->call('vendor:publish', ['--tag' => 'watchtower-config', '--force' => true]);
        }

        $this->configureFrontend($dsn, $dryRun);
        $this->installMcp($dsn, $dryRun);

        if ($dryRun) {
            $this->info('--dry-run: no files were modified.');
        } else {
            $this->info('Verify with: php artisan watchtower:test');
        }

        return self::SUCCESS;
    }

    private function installMcp(string $dsn, bool $dryRun): void
    {
        if ((bool) $this->option('no-mcp')) {
            return;
        }

        $parsed = Dsn::parse($dsn);

        if ($parsed === null) {
            return;
        }

        $url           = sprintf('%s://%s/mcp', $parsed['scheme'], $parsed['host_with_port']);
        $publicKey     = $parsed['public_key'];
        $manualCommand = sprintf('claude mcp add watchtower %s --header "Authorization: Bearer %s"', $url, $publicKey);
        $registrar     = app(ClaudeMcpRegistrar::class);
        $binary        = $registrar->find();

        if ($binary === null) {
            $this->warn('Claude Code CLI (claude) not detected on PATH.');
            $this->line('To register the Watchtower MCP server with Claude, run:');
            $this->line('  '.$manualCommand);

            return;
        }

        if ($dryRun) {
            $this->line('Would run: '.$manualCommand);

            return;
        }

        $result = $registrar->register($binary, 'watchtower', $url, $publicKey, base_path());

        if ($result['success']) {
            $this->info('Registered Watchtower MCP server with Claude Code (key: watchtower).');

            return;
        }

        $this->warn('Failed to register Watchtower MCP with Claude Code:');

        if ($result['output'] !== '') {
            $this->line($result['output']);
        }

        $this->line('Run manually: '.$manualCommand);
    }

    private function resolveDsn(): ?string
    {
        $dsn = (string) ($this->option('dsn') ?? '');

        if ($dsn === '') {
            $dsn = (string) (env('WATCHTOWER_DSN') ?? env('SENTRY_LARAVEL_DSN') ?? '');
        }

        if ($dsn === '') {
            $dsn = (string) $this->ask('Watchtower DSN');
        }

        if (Dsn::parse($dsn) === null) {
            $this->error('Invalid Watchtower DSN.');
            $this->line('Expected format: http://{public_key}@{host}/{numeric-project-id}');
            $this->line('Example:         http://abc123@watchtower.test/42');

            return null;
        }

        return $dsn;
    }

    private function writeEnvKeys(string $dsn, bool $dryRun): void
    {
        $envPath        = base_path('.env');
        $envExamplePath = base_path('.env.example');
        $isLocal        = (string) env('APP_ENV', 'production') === 'local';

        $sentryValue = $dsn;

        if ($isLocal && $this->confirm('APP_ENV=local detected. Send local exceptions to Watchtower via SENTRY_LARAVEL_DSN?', true) === false) {
            $sentryValue = 'null';
        }

        if ($dryRun) {
            $this->line('Would set in .env: WATCHTOWER_DSN, SENTRY_LARAVEL_DSN');

            return;
        }

        $env = new EnvWriter($envPath);
        $env->set('WATCHTOWER_DSN', $dsn);
        $env->set('SENTRY_LARAVEL_DSN', $sentryValue);

        if (is_file($envExamplePath)) {
            $example = new EnvWriter($envExamplePath);
            $example->set('WATCHTOWER_DSN', '');
            $example->set('SENTRY_LARAVEL_DSN', '');
        }

        $this->info('Wrote DSN keys to .env');
    }

    private function confirmPii(bool $dryRun): void
    {
        // Opt-in across every env: BeforeSend scrubs known secret keys, but the safer
        // default for a fresh install is to ship without request body / IP capture
        // until the operator has confirmed scrub coverage matches their data shape.
        $enable = (bool) $this->confirm(self::PII_CONFIRM_QUESTION, false);
        $value  = $enable ? 'true' : 'false';

        if ($dryRun) {
            $this->line("Would set in .env: SENTRY_SEND_DEFAULT_PII={$value}");

            return;
        }

        $env = new EnvWriter(base_path('.env'));
        $env->set('SENTRY_SEND_DEFAULT_PII', $value);
        $this->info("Wrote SENTRY_SEND_DEFAULT_PII={$value} to .env");
    }

    private function writeBreadcrumbEnvKeys(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would set in .env (if absent): '.implode(', ', array_keys(self::BREADCRUMB_KEYS)));

            return;
        }

        $env     = new EnvWriter(base_path('.env'));
        $written = [];

        foreach (self::BREADCRUMB_KEYS as $key => $value) {
            if ($env->setIfAbsent($key, $value)) {
                $written[] = $key;
            }
        }

        if ($written === []) {
            $this->line('Breadcrumb keys already present in .env — left as-is.');

            return;
        }

        $this->info('Wrote '.count($written).' breadcrumb key(s) to .env: '.implode(', ', $written));
    }

    private function patchBootstrap(bool $dryRun): void
    {
        $path = base_path('bootstrap/app.php');

        if (! is_file($path)) {
            $this->warn('bootstrap/app.php not found; skipping patch.');

            return;
        }

        $patcher  = BootstrapPatcher::fromFile($path);
        $original = (string) file_get_contents($path);

        if ($patcher->alreadyWired()) {
            $this->info('bootstrap/app.php already wires Integration::handles($exceptions).');

            return;
        }

        $patched = $patcher->patch();

        if ($patched === null) {
            $this->error("Couldn't recognize bootstrap/app.php shape. Add `Integration::handles(\$exceptions);` inside `withExceptions(...)` manually.");

            return;
        }

        if ($dryRun) {
            $this->line('--- bootstrap/app.php (current)');
            $this->line('+++ bootstrap/app.php (patched)');
            $this->line($this->unifiedDiff($original, $patched));

            return;
        }

        file_put_contents($path, $patched);
        $this->info('Patched bootstrap/app.php with Integration::handles($exceptions).');
    }

    private function configureFrontend(string $dsn, bool $dryRun): void
    {
        $hasVite = is_file(base_path('vite.config.js')) || is_file(base_path('vite.config.ts'));

        if (! $hasVite) {
            return;
        }

        if ($dryRun) {
            $this->line('Would set in .env: VITE_SENTRY_DSN, VITE_SENTRY_TUNNEL, VITE_SENTRY_ENVIRONMENT');
            $this->line('Would publish: resources/js/vendor/watchtower-user-context.js');

            return;
        }

        $env = new EnvWriter(base_path('.env'));
        $env->set('VITE_SENTRY_DSN', $dsn);
        $env->set('VITE_SENTRY_TUNNEL', '/api/watchtower-relay');
        $env->set('VITE_SENTRY_ENVIRONMENT', '${APP_ENV}');

        if (is_file(base_path('.env.example'))) {
            $example = new EnvWriter(base_path('.env.example'));
            $example->set('VITE_SENTRY_DSN', '');
            $example->set('VITE_SENTRY_TUNNEL', '/api/watchtower-relay');
            $example->set('VITE_SENTRY_ENVIRONMENT', '${APP_ENV}');
        }

        $this->info('Wrote VITE_SENTRY_* keys to .env');

        // --force false so re-running install doesn't overwrite user edits to the helper.
        $this->call('vendor:publish', ['--tag' => 'watchtower-js', '--force' => false]);

        // Snippets contain literal '<' / '>' (Blade tags, JSX-style imports) — emit
        // as raw so Symfony's OutputFormatter doesn't parse them as styles. Each
        // line is a separate writeln so testing tools see one assertion per line.
        $this->output->writeln('Add to your Vite entry (e.g. resources/js/app.js):', OutputInterface::OUTPUT_RAW);
        $this->writeRawLines(<<<'JS'

            import * as Sentry from '@sentry/browser';
            import { applyWatchtowerUser } from './vendor/watchtower-user-context.js';

            if (import.meta.env.VITE_SENTRY_DSN) {
                Sentry.init({
                    dsn: import.meta.env.VITE_SENTRY_DSN,
                    tunnel: import.meta.env.VITE_SENTRY_TUNNEL,
                    environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
                    sendDefaultPii: false,
                    tracesSampleRate: 0,
                    denyUrls: [
                        /^chrome-extension:\/\//i,
                        /^moz-extension:\/\//i,
                        /^safari-extension:\/\//i,
                        /^safari-web-extension:\/\//i,
                    ],
                    // Uncomment to suppress common browser noise once you've
                    // confirmed it isn't masking real issues in your app:
                    // ignoreErrors: [
                    //     'ResizeObserver loop limit exceeded',
                    //     'ResizeObserver loop completed with undelivered notifications',
                    //     'Script error.',
                    //     'Non-Error promise rejection captured',
                    //     'Network request failed',
                    //     'NetworkError',
                    //     'Failed to fetch',
                    //     'Load failed',
                    //     'top.GLOBALS',
                    //     'fb_xd_fragment',
                    // ],
                });
                applyWatchtowerUser();
            }

            JS);

        $this->output->writeln('Paste into your root Blade layout <head> so browser exceptions know who is logged in:', OutputInterface::OUTPUT_RAW);
        $this->writeRawLines(<<<'BLADE'

            <meta name="watchtower-user-id" content="{{ auth()->id() ?? '' }}">
            <meta name="watchtower-user-email" content="{{ auth()->user()?->email ?? '' }}">
            <meta name="watchtower-user-name" content="{{ auth()->user()?->name ?? '' }}">

            BLADE);
    }

    private function writeRawLines(string $block): void
    {
        foreach (explode("\n", $block) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }

    private function unifiedDiff(string $a, string $b): string
    {
        $tmpA = tempnam(sys_get_temp_dir(), 'wt-a-');
        $tmpB = tempnam(sys_get_temp_dir(), 'wt-b-');

        try {
            file_put_contents((string) $tmpA, $a);
            file_put_contents((string) $tmpB, $b);

            $output = [];
            $status = 0;
            exec('diff -u '.escapeshellarg((string) $tmpA).' '.escapeshellarg((string) $tmpB).' 2>/dev/null', $output, $status);

            return implode("\n", $output);
        } finally {
            @unlink((string) $tmpA);
            @unlink((string) $tmpB);
        }
    }
}
