<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\WatchtowerLaravel\Support\BootstrapPatcher;
use Phattarachai\WatchtowerLaravel\Support\ClaudeMcpRegistrar;
use Phattarachai\WatchtowerLaravel\Support\Dsn;
use Phattarachai\WatchtowerLaravel\Support\EnvWriter;

class InstallCommand extends Command
{
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

        if ($isLocal && $this->confirm('APP_ENV=local detected. Send local exceptions to Watchtower via SENTRY_LARAVEL_DSN?', false) === false) {
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
        $this->line('Add to your Vite entry (e.g. resources/js/app.js):');
        $this->line(<<<'JS'

            import * as Sentry from '@sentry/browser';

            Sentry.init({
                dsn: import.meta.env.VITE_SENTRY_DSN,
                tunnel: import.meta.env.VITE_SENTRY_TUNNEL,
                environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
            });

            JS);
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
