<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Support\BootstrapPatcher;
use Phattarachai\WatchtowerLaravel\Support\ClaudeMcpRegistrar;
use Phattarachai\WatchtowerLaravel\Support\Dsn;
use Phattarachai\WatchtowerLaravel\Support\EmbeddedUiPatcher;
use Phattarachai\WatchtowerLaravel\Support\EnvWriter;
use Phattarachai\WatchtowerLaravel\Support\FilamentPanelDetector;
use Phattarachai\WatchtowerLaravel\Support\FrontendPatcher;
use Phattarachai\WatchtowerLaravel\Support\LayoutDetector;
use Phattarachai\WatchtowerLaravel\Support\PackageManagerDetector;
use Phattarachai\WatchtowerLaravel\Support\ViteEntryDetector;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    public const string PII_CONFIRM_QUESTION = 'Enable SENTRY_SEND_DEFAULT_PII (attach request data + IP — Watchtower scrubs secrets via BeforeSend)?';

    /** @var array<string, string> Breadcrumb env keys set only when absent. */
    private const array BREADCRUMB_KEYS = [
        'SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED' => 'true',
        'SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED' => 'false',
        'SENTRY_BREADCRUMBS_CACHE_ENABLED' => 'true',
        'SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED' => 'true',
        'SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED' => 'true',
    ];

    /** @var list<string> */
    private const array MODES = ['relay', 'standalone', 'dual'];

    protected $signature = 'watchtower:install
        {--dsn= : Watchtower DSN, e.g. http://key@host/42}
        {--mode= : relay (default), standalone or dual}
        {--standalone : Shorthand for --mode=standalone}
        {--dry-run : Print intended changes without writing files}
        {--no-mcp : Skip registering the Watchtower MCP server with Claude Code}
        {--patch-js : Auto-inject the Sentry init snippet into detected Vite entries}
        {--patch-views : Auto-inject the watchtower-user-* meta tags into detected layouts}';

    protected $description = 'Wire up Watchtower error tracking: DSN, exception handler, frontend tunnel, Claude MCP.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode = $this->resolveMode();

        if ($mode === null) {
            return self::FAILURE;
        }

        return $mode === 'relay'
            ? $this->installRelay($dryRun)
            : $this->installEmbedded($mode, $dryRun);
    }

    private function resolveMode(): ?string
    {
        $mode = (bool) $this->option('standalone')
            ? 'standalone'
            : (string) ($this->option('mode') ?? '');

        if ($mode === '') {
            return 'relay';
        }

        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        $this->error("Unknown mode [{$mode}]. Expected one of: ".implode(', ', self::MODES).'.');

        return null;
    }

    private function installRelay(bool $dryRun): int
    {
        $dsn = $this->resolveDsn();

        if ($dsn === null) {
            return self::FAILURE;
        }

        $this->writeEnvKeys($dsn, $dryRun);
        $this->confirmPii($dryRun);
        $this->writeBreadcrumbEnvKeys($dryRun);
        $this->patchBootstrap($dryRun);
        $this->publishConfig($dryRun);
        $this->configureFrontend($dsn, $dryRun);
        $this->installMcp($dsn, $dryRun);
        $this->emitClosing($dryRun);

        return self::SUCCESS;
    }

    /**
     * Standalone and dual both own tables, an ingest route and the embedded UI
     * in this app. Dual additionally keeps the upstream DSN so browser
     * envelopes are still forwarded to the central Watchtower.
     */
    private function installEmbedded(string $mode, bool $dryRun): int
    {
        $upstream = $mode === 'dual' ? $this->resolveDsn() : null;

        if ($mode === 'dual' && $upstream === null) {
            return self::FAILURE;
        }

        $this->writeModeEnv($mode, $dryRun);

        if ($upstream !== null) {
            $this->writeEnvKeys($upstream, $dryRun);
        }

        $this->migrate($dryRun);
        $project = $this->ensureFirstProject($dryRun);
        $localDsn = $this->localDsn($project);

        $this->writeLocalSentryDsn($localDsn, $dryRun);
        $this->confirmPii($dryRun);
        $this->writeBreadcrumbEnvKeys($dryRun);
        $this->patchBootstrap($dryRun);
        $this->publishConfig($dryRun);
        $this->publishInertiaPage($dryRun);
        $this->patchHostBuildFiles($dryRun);
        $this->configureFrontend($localDsn, $dryRun);
        $this->installEmbeddedMcp($project, $dryRun);
        $this->emitAuthSnippet();
        $this->emitEmbeddedNextSteps($localDsn);
        $this->emitClosing($dryRun);

        return self::SUCCESS;
    }

    private function publishConfig(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would publish: config/watchtower.php');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'watchtower-config', '--force' => true]);
    }

    private function emitClosing(bool $dryRun): void
    {
        if ($dryRun) {
            $this->info('--dry-run: no files were modified.');

            return;
        }

        $this->info('Verify with: php artisan watchtower:test');
    }

    private function writeModeEnv(string $mode, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line("Would set in .env: WATCHTOWER_MODE={$mode}");

            return;
        }

        new EnvWriter(base_path('.env'))->set('WATCHTOWER_MODE', $mode);

        if (is_file(base_path('.env.example'))) {
            new EnvWriter(base_path('.env.example'))->set('WATCHTOWER_MODE', $mode);
        }

        $this->info("Wrote WATCHTOWER_MODE={$mode} to .env");
    }

    private function migrate(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would run: php artisan migrate --force (creates the watchtower_* tables)');

            return;
        }

        $this->call('migrate', [
            '--force' => true,
            '--realpath' => true,
            '--path' => dirname(__DIR__, 2).'/database/migrations',
        ]);

        $this->call('migrate', ['--force' => true]);
    }

    /**
     * The first project is this app itself. A second run finds it and leaves
     * both the row and its key alone.
     */
    private function ensureFirstProject(bool $dryRun): ?Project
    {
        $name = (string) config('app.name', 'Watchtower');

        if ($dryRun) {
            $this->line("Would create the first watchtower_projects row: {$name} (platform laravel) — unless one already exists.");

            return null;
        }

        $existing = Project::query()->orderBy('id')->first();

        if ($existing !== null) {
            $this->line("Project [{$existing->name}] already exists — reusing it.");

            return $existing;
        }

        $project = Project::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'platform' => 'laravel',
            'public_key' => Project::newPublicKey(),
            'is_active' => true,
        ]);

        $this->info("Created project [{$project->name}] (id {$project->getKey()}).");

        return $project;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'project' : $base;
        $slug = $base;

        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function localDsn(?Project $project): string
    {
        $appUrl = (string) config('app.url', 'http://localhost');

        if ($project !== null) {
            return $project->buildDsn($appUrl);
        }

        $prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');

        return rtrim($appUrl, '/').'/'.$prefix.'/{project-id}';
    }

    private function writeLocalSentryDsn(string $dsn, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would set in .env: SENTRY_LARAVEL_DSN=<local project DSN>');

            return;
        }

        new EnvWriter(base_path('.env'))->set('SENTRY_LARAVEL_DSN', $dsn);

        $this->info('Wrote SENTRY_LARAVEL_DSN to .env');
        $this->line('  '.$dsn);
    }

    private function publishInertiaPage(bool $dryRun): void
    {
        $extension = EmbeddedUiPatcher::pageExtension(base_path());

        if ($dryRun) {
            $this->line("Would publish: resources/js/pages/Watchtower.{$extension}");

            return;
        }

        $target = resource_path("js/pages/Watchtower.{$extension}");

        if (is_file($target)) {
            $this->line('  resources/js/pages/Watchtower.'.$extension.' already published.');

            return;
        }

        @mkdir(dirname($target), 0755, true);
        copy(dirname(__DIR__, 2).'/resources/js/pages/Watchtower.jsx', $target);

        $this->line('  Published resources/js/pages/Watchtower.'.$extension.'.');
    }

    /**
     * The two build-tool edits the embedded UI cannot work around: the
     * `@watchtower` Vite alias and Tailwind's `prefix(tw)` + `@source` lines.
     */
    private function patchHostBuildFiles(bool $dryRun): void
    {
        $viteConfigs = EmbeddedUiPatcher::viteConfigPaths(base_path());

        if ($dryRun) {
            $this->line('Would add the @watchtower Vite alias and write resources/css/watchtower.css.');

            return;
        }

        foreach ($viteConfigs as $config) {
            $already = EmbeddedUiPatcher::hasAlias($config);
            $patched = EmbeddedUiPatcher::patchViteAlias($config);

            $this->line(match (true) {
                $already => '  '.basename($config).' already declares the @watchtower alias.',
                $patched => '  Added the @watchtower alias to '.basename($config).'.',
                default => '  Could not patch '.basename($config).' — add a resolve.alias entry for @watchtower manually.',
            });
        }

        if ($viteConfigs === []) {
            $this->warn('No vite.config.js/ts found — add the @watchtower alias once you have one.');
        }

        $this->writeWatchtowerCss();
    }

    private function writeWatchtowerCss(): void
    {
        $path = EmbeddedUiPatcher::cssFilePath(base_path());
        $existed = EmbeddedUiPatcher::hasTailwindPrefix($path) && EmbeddedUiPatcher::hasTailwindSource($path);

        if (! EmbeddedUiPatcher::writeCssFile(base_path())) {
            $this->warn('Could not write resources/css/watchtower.css — create it manually with:');
            $this->line(EmbeddedUiPatcher::cssContents());

            return;
        }

        $this->line($existed
            ? '  resources/css/watchtower.css already present.'
            : '  Wrote resources/css/watchtower.css (imported by the published page stub).');
    }

    private function emitAuthSnippet(): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
        $this->output->writeln('Grant access to the embedded UI from a service provider (skipped entirely in the local environment):', OutputInterface::OUTPUT_RAW);
        $this->writeRawSnippet(<<<'PHP'
            use Illuminate\Support\Facades\Gate;
            use Phattarachai\WatchtowerLaravel\Watchtower;

            Watchtower::auth(fn ($request): bool => $request->user()?->isAdmin() === true);

            // …or define the gate instead:
            Gate::define('viewWatchtower', fn ($user): bool => $user->isAdmin());
            PHP);
    }

    private function emitEmbeddedNextSteps(string $dsn): void
    {
        $prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');

        $this->output->writeln('Next steps:', OutputInterface::OUTPUT_RAW);
        $this->output->writeln('  1. npm run build (or npm run dev)', OutputInterface::OUTPUT_RAW);
        $this->output->writeln('  2. Visit /'.$prefix, OutputInterface::OUTPUT_RAW);
        $this->output->writeln('  3. php artisan watchtower:doctor to confirm the wiring', OutputInterface::OUTPUT_RAW);
        $this->output->writeln('  Project DSN: '.$dsn, OutputInterface::OUTPUT_RAW);
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
    }

    private function installEmbeddedMcp(?Project $project, bool $dryRun): void
    {
        if ((bool) $this->option('no-mcp')) {
            return;
        }

        $prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');
        $url = rtrim((string) config('app.url', 'http://localhost'), '/').'/'.$prefix.'/mcp';
        $key = $project->public_key ?? '{project-public-key}';

        $this->registerMcp($url, $key, $dryRun);
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

        $this->registerMcp(
            sprintf('%s://%s/mcp', $parsed['scheme'], $parsed['host_with_port']),
            $parsed['public_key'],
            $dryRun,
        );
    }

    private function registerMcp(string $url, string $publicKey, bool $dryRun): void
    {
        $manualCommand = sprintf('claude mcp add --transport http --scope project watchtower %s --header "Authorization: Bearer %s"', $url, $publicKey);
        $registrar = app(ClaudeMcpRegistrar::class);
        $binary = $registrar->find();

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
            $this->line('Wrote project-scoped .mcp.json — commit it so teammates get the MCP on pull.');

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
        $envPath = base_path('.env');
        $envExamplePath = base_path('.env.example');
        $isLocal = (string) env('APP_ENV', 'production') === 'local';

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
        $value = $enable ? 'true' : 'false';

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

        $env = new EnvWriter(base_path('.env'));
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

        $patcher = BootstrapPatcher::fromFile($path);
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
        $viteDetector = new ViteEntryDetector(base_path());

        if ($viteDetector->configPath() === null) {
            return;
        }

        if ($dryRun) {
            $this->line('Would set in .env: VITE_SENTRY_DSN, VITE_SENTRY_TUNNEL, VITE_SENTRY_ENVIRONMENT');
            $this->line('Would publish: resources/js/vendor/watchtower.js');

            return;
        }

        $this->writeFrontendEnvKeys($dsn);

        // --force false so re-running install doesn't overwrite user edits to the helper.
        $this->call('vendor:publish', ['--tag' => 'watchtower-js', '--force' => false]);

        $this->emitFrontendPlacement($viteDetector);
    }

    private function writeFrontendEnvKeys(string $dsn): void
    {
        $env = new EnvWriter(base_path('.env'));
        $env->set('VITE_SENTRY_DSN', $dsn);
        $env->set('VITE_SENTRY_TUNNEL', '/api/watchtower-relay');
        $env->set('VITE_SENTRY_ENVIRONMENT', '${APP_ENV}', raw: true);

        if (is_file(base_path('.env.example'))) {
            $example = new EnvWriter(base_path('.env.example'));
            $example->set('VITE_SENTRY_DSN', '');
            $example->set('VITE_SENTRY_TUNNEL', '/api/watchtower-relay');
            $example->set('VITE_SENTRY_ENVIRONMENT', '${APP_ENV}', raw: true);
        }

        $this->info('Wrote VITE_SENTRY_* keys to .env');
    }

    private function emitFrontendPlacement(ViteEntryDetector $viteDetector): void
    {
        $jsEntries = $viteDetector->jsEntries();
        $layouts = (new LayoutDetector(base_path()))->layouts();
        $panels = (new FilamentPanelDetector(base_path()))->panels();
        $patchJs = (bool) $this->option('patch-js');
        $patchViews = (bool) $this->option('patch-views');

        $this->emitJsBlock($jsEntries, $patchJs);
        $this->emitBladeBlock($layouts, $patchViews);
        $this->emitFilamentBlock($panels);
    }

    /**
     * @param  list<string>  $entries
     */
    private function emitJsBlock(array $entries, bool $patch): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);

        if ($entries === []) {
            $this->output->writeln('No Vite JS entries detected from vite.config — paste the snippet below into your JS entry manually:', OutputInterface::OUTPUT_RAW);
            $this->writeRawSnippet(FrontendPatcher::renderJsSnippet());
            $this->emitSentryBrowserHint();

            return;
        }

        $this->output->writeln(sprintf('Detected Vite entries (%d) — add the marked block to the top of each:', count($entries)), OutputInterface::OUTPUT_RAW);

        foreach ($entries as $entry) {
            $absolute = base_path($entry);
            $status = $patch ? $this->patchJsAndReport($absolute) : $this->describeJsState($absolute);

            $this->output->writeln('  • '.$entry.$status, OutputInterface::OUTPUT_RAW);
        }

        if (! $patch) {
            $this->writeRawSnippet(FrontendPatcher::renderJsSnippet());
            $this->output->writeln('Re-run with --patch-js to inject the block above into every detected entry.', OutputInterface::OUTPUT_RAW);
        }

        $this->emitSentryBrowserHint();
    }

    private function emitSentryBrowserHint(): void
    {
        $detector = new PackageManagerDetector(base_path());

        if ($detector->hasDependency('@sentry/browser')) {
            $this->output->writeln('@sentry/browser is already in package.json — no install step needed.', OutputInterface::OUTPUT_RAW);

            return;
        }

        $this->output->writeln('Install the browser SDK (matches your lockfile):', OutputInterface::OUTPUT_RAW);
        $this->writeRawSnippet($detector->installCommand('@sentry/browser'));
    }

    /**
     * @param  list<string>  $layouts
     */
    private function emitBladeBlock(array $layouts, bool $patch): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);

        if ($layouts === []) {
            $this->output->writeln('No Blade layouts with <head> detected — paste the snippet below into your root layout manually:', OutputInterface::OUTPUT_RAW);
            $this->writeRawSnippet(FrontendPatcher::renderBladeSnippet());

            return;
        }

        $this->output->writeln(sprintf('Detected Blade layouts (%d) — insert the marked block inside <head>:', count($layouts)), OutputInterface::OUTPUT_RAW);

        foreach ($layouts as $layout) {
            $absolute = base_path($layout);
            $status = $patch ? $this->patchBladeAndReport($absolute) : $this->describeBladeState($absolute);

            $this->output->writeln('  • '.$layout.$status, OutputInterface::OUTPUT_RAW);
        }

        if (! $patch) {
            $this->writeRawSnippet(FrontendPatcher::renderBladeSnippet());
            $this->output->writeln('Re-run with --patch-views to inject the block above into every detected layout.', OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * @param  list<string>  $panels
     */
    private function emitFilamentBlock(array $panels): void
    {
        if ($panels === []) {
            return;
        }

        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
        $this->output->writeln(sprintf('Detected Filament panel providers (%d) — Filament admin pages do not use the Blade layouts above.', count($panels)), OutputInterface::OUTPUT_RAW);

        foreach ($panels as $panel) {
            $this->output->writeln('  • '.$panel, OutputInterface::OUTPUT_RAW);
        }

        $this->output->writeln('Register a render hook in each panel provider to emit the @watchtowerUser meta tags inside Filament <head>:', OutputInterface::OUTPUT_RAW);
        $this->writeRawSnippet(<<<'PHP'
            use Filament\View\PanelsRenderHook;
            use Illuminate\Support\Facades\Blade;

            $panel->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('@watchtowerUser'),
            );
            PHP);
    }

    private function describeJsState(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return ' (missing on disk — create it first)';
        }

        $contents = (string) file_get_contents($absolutePath);

        return str_contains($contents, FrontendPatcher::MARKER_JS_OPEN) ? ' (already patched)' : '';
    }

    private function describeBladeState(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return ' (missing on disk)';
        }

        $contents = (string) file_get_contents($absolutePath);

        return str_contains($contents, FrontendPatcher::MARKER_BLADE_OPEN) ? ' (already patched)' : '';
    }

    private function patchJsAndReport(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return ' (skipped — file missing)';
        }

        $contents = (string) file_get_contents($absolutePath);

        if (str_contains($contents, FrontendPatcher::MARKER_JS_OPEN)) {
            return ' (already patched)';
        }

        return FrontendPatcher::patchJsEntry($absolutePath) ? ' (patched)' : ' (patch failed)';
    }

    private function patchBladeAndReport(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return ' (skipped — file missing)';
        }

        $contents = (string) file_get_contents($absolutePath);

        if (str_contains($contents, FrontendPatcher::MARKER_BLADE_OPEN)) {
            return ' (already patched)';
        }

        return FrontendPatcher::patchBladeLayout($absolutePath) ? ' (patched)' : ' (patch failed — no </head> found)';
    }

    private function writeRawSnippet(string $snippet): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
        $this->writeRawLines($snippet);
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
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
