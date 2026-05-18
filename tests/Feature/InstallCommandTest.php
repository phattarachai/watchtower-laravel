<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Console\InstallCommand;
use Phattarachai\WatchtowerLaravel\Support\ClaudeMcpRegistrar;

beforeEach(function (): void {
    $this->bootstrapPath = base_path('bootstrap/app.php');
    $this->envPath       = base_path('.env');
    $this->originalBootstrap = is_file($this->bootstrapPath) ? file_get_contents($this->bootstrapPath) : null;
    $this->originalEnv       = is_file($this->envPath) ? file_get_contents($this->envPath) : null;

    if (! is_dir(dirname($this->bootstrapPath))) {
        mkdir(dirname($this->bootstrapPath), 0777, true);
    }

    file_put_contents($this->bootstrapPath, <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
PHP);

    file_put_contents($this->envPath, "APP_NAME=Testing\n");

    // Default: claude not on PATH — guarantees tests never shell out to a real `claude`
    // and never mutate a developer's actual MCP config. Individual tests override this
    // binding to exercise the found-and-registered path.
    $this->app->singleton(ClaudeMcpRegistrar::class, fn (): ClaudeMcpRegistrar => new class extends ClaudeMcpRegistrar
    {
        public function find(): ?string
        {
            return null;
        }
    });
});

afterEach(function (): void {
    if ($this->originalBootstrap !== null) {
        file_put_contents($this->bootstrapPath, $this->originalBootstrap);
    } else {
        @unlink($this->bootstrapPath);
    }

    if ($this->originalEnv !== null) {
        file_put_contents($this->envPath, $this->originalEnv);
    } else {
        @unlink($this->envPath);
    }

    @unlink(base_path('vite.config.js'));
    @unlink(resource_path('js/vendor/watchtower.js'));
    @rmdir(resource_path('js/vendor'));
    @rmdir(resource_path('js'));
});

it('writes env keys and patches bootstrap/app.php', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    $env       = (string) file_get_contents($this->envPath);
    $bootstrap = (string) file_get_contents($this->bootstrapPath);

    expect($env)->toContain('WATCHTOWER_DSN=http://abc@watchtower.test/42')
        ->and($env)->toContain('SENTRY_LARAVEL_DSN=http://abc@watchtower.test/42');

    expect($bootstrap)->toContain('use Sentry\\Laravel\\Integration;')
        ->and($bootstrap)->toContain('Integration::handles($exceptions);');
});

it('is idempotent on a second run', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    $bootstrap = (string) file_get_contents($this->bootstrapPath);

    expect(substr_count($bootstrap, 'Integration::handles($exceptions)'))->toBe(1);
    expect(substr_count($bootstrap, 'use Sentry\\Laravel\\Integration;'))->toBe(1);
});

it('does not modify files in dry-run mode', function (): void {
    $envBefore       = (string) file_get_contents($this->envPath);
    $bootstrapBefore = (string) file_get_contents($this->bootstrapPath);

    $this->artisan('watchtower:install', [
        '--dsn'     => 'http://abc@watchtower.test/42',
        '--dry-run' => true,
    ])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toBe($envBefore);
    expect(file_get_contents($this->bootstrapPath))->toBe($bootstrapBefore);
});

it('fails on invalid DSN', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://no-project@watchtower.test/'])
        ->assertExitCode(1);
});

it('prints the manual claude mcp add command when claude is not on PATH', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Claude Code CLI (claude) not detected on PATH.')
        ->expectsOutputToContain('claude mcp add watchtower http://watchtower.test/mcp --header "Authorization: Bearer abc"')
        ->assertExitCode(0);
});

it('skips the MCP step entirely when --no-mcp is passed', function (): void {
    $this->artisan('watchtower:install', [
        '--dsn'    => 'http://abc@watchtower.test/42',
        '--no-mcp' => true,
    ])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->doesntExpectOutputToContain('claude mcp add')
        ->doesntExpectOutputToContain('MCP')
        ->assertExitCode(0);
});

it('registers the MCP with Claude when the binary is available', function (): void {
    $this->app->singleton(ClaudeMcpRegistrar::class, fn (): ClaudeMcpRegistrar => new class extends ClaudeMcpRegistrar
    {
        public ?array $lastCall = null;

        public function find(): ?string
        {
            return '/usr/local/bin/claude';
        }

        public function register(string $binary, string $name, string $url, string $bearer, string $workingDir): array
        {
            $this->lastCall = compact('binary', 'name', 'url', 'bearer', 'workingDir');

            return ['success' => true, 'output' => ''];
        }
    });

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Registered Watchtower MCP server with Claude Code')
        ->assertExitCode(0);

    $registrar = app(ClaudeMcpRegistrar::class);
    expect($registrar->lastCall)->toMatchArray([
        'binary' => '/usr/local/bin/claude',
        'name'   => 'watchtower',
        'url'    => 'http://watchtower.test/mcp',
        'bearer' => 'abc',
    ]);
});

it('prints intended MCP command in dry-run mode when claude is available', function (): void {
    $this->app->singleton(ClaudeMcpRegistrar::class, fn (): ClaudeMcpRegistrar => new class extends ClaudeMcpRegistrar
    {
        public bool $registerCalled = false;

        public function find(): ?string
        {
            return '/usr/local/bin/claude';
        }

        public function register(string $binary, string $name, string $url, string $bearer, string $workingDir): array
        {
            $this->registerCalled = true;

            return ['success' => true, 'output' => ''];
        }
    });

    $this->artisan('watchtower:install', [
        '--dsn'     => 'http://abc@watchtower.test/42',
        '--dry-run' => true,
    ])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Would run: claude mcp add watchtower http://watchtower.test/mcp')
        ->assertExitCode(0);

    expect(app(ClaudeMcpRegistrar::class)->registerCalled)->toBeFalse();
});

it('writes SENTRY_SEND_DEFAULT_PII=true when user confirms', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->envPath))
        ->toContain('SENTRY_SEND_DEFAULT_PII=true');
});

it('writes SENTRY_SEND_DEFAULT_PII=false when user declines', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'no')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->envPath))
        ->toContain('SENTRY_SEND_DEFAULT_PII=false');
});

it('writes 5 breadcrumb env keys with sensible defaults when absent', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    $env = (string) file_get_contents($this->envPath);

    expect($env)->toContain('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=true')
        ->and($env)->toContain('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED=false')
        ->and($env)->toContain('SENTRY_BREADCRUMBS_CACHE_ENABLED=true')
        ->and($env)->toContain('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED=true')
        ->and($env)->toContain('SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED=true');
});

it('preserves existing breadcrumb keys instead of overwriting them', function (): void {
    file_put_contents(
        $this->envPath,
        "APP_NAME=Testing\nSENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=false\nSENTRY_BREADCRUMBS_CACHE_ENABLED=false\n"
    );

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    $env = (string) file_get_contents($this->envPath);

    expect($env)->toContain('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=false')
        ->and($env)->toContain('SENTRY_BREADCRUMBS_CACHE_ENABLED=false')
        ->and(substr_count($env, 'SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED='))->toBe(1)
        ->and(substr_count($env, 'SENTRY_BREADCRUMBS_CACHE_ENABLED='))->toBe(1)
        ->and($env)->toContain('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED=true');
});

it('does not write PII or breadcrumb keys in dry-run mode', function (): void {
    $envBefore = (string) file_get_contents($this->envPath);

    $this->artisan('watchtower:install', [
        '--dsn'     => 'http://abc@watchtower.test/42',
        '--dry-run' => true,
    ])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Would set in .env: SENTRY_SEND_DEFAULT_PII=true')
        ->expectsOutputToContain('Would set in .env (if absent): SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED')
        ->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toBe($envBefore);
});

it('prints the initWatchtower() snippet and @watchtowerUser directive when Vite is present', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain("import { initWatchtower } from './vendor/watchtower.js';")
        ->expectsOutputToContain('initWatchtower();')
        ->expectsOutputToContain('@watchtowerUser')
        ->assertExitCode(0);

    expect(is_file(resource_path('js/vendor/watchtower.js')))->toBeTrue();
});

it('suggests the package-manager-specific install command for @sentry/browser', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");
    file_put_contents(base_path('package.json'), '{}');
    file_put_contents(base_path('pnpm-lock.yaml'), '');

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Install the browser SDK')
        ->expectsOutputToContain('pnpm add @sentry/browser')
        ->assertExitCode(0);

    @unlink(base_path('package.json'));
    @unlink(base_path('pnpm-lock.yaml'));
});

it('suggests bun add when bun.lockb is present', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");
    file_put_contents(base_path('package.json'), '{}');
    file_put_contents(base_path('bun.lockb'), '');

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('bun add @sentry/browser')
        ->assertExitCode(0);

    @unlink(base_path('package.json'));
    @unlink(base_path('bun.lockb'));
});

it('skips the install hint when @sentry/browser is already a declared dependency', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");
    file_put_contents(base_path('package.json'), json_encode([
        'dependencies' => ['@sentry/browser' => '^9.0.0'],
    ]));
    file_put_contents(base_path('package-lock.json'), '{}');

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('@sentry/browser is already in package.json')
        ->doesntExpectOutputToContain('Install the browser SDK')
        ->assertExitCode(0);

    @unlink(base_path('package.json'));
    @unlink(base_path('package-lock.json'));
});

it('writes VITE_SENTRY_ENVIRONMENT with an unescaped dotenv reference', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->assertExitCode(0);

    $env = (string) file_get_contents($this->envPath);

    expect($env)->toContain('VITE_SENTRY_ENVIRONMENT="${APP_ENV}"')
        ->and($env)->not->toContain('\\${APP_ENV}');
});

it('lists detected Vite entries when laravel({ input: [...] }) is parseable', function (): void {
    file_put_contents(base_path('vite.config.js'), <<<'JS'
        export default {
            plugins: [
                laravel({ input: ['resources/js/app.js', 'resources/css/app.css'] }),
            ],
        };
        JS);

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Detected Vite entries (1)')
        ->expectsOutputToContain('resources/js/app.js')
        ->assertExitCode(0);
});

it('lists detected Blade layouts containing <head>', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");

    @mkdir(resource_path('views/components/layouts'), 0777, true);
    file_put_contents(
        resource_path('views/components/layouts/app.blade.php'),
        '<html><head></head><body>{{ $slot }}</body></html>'
    );

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Detected Blade layouts (1)')
        ->expectsOutputToContain('resources/views/components/layouts/app.blade.php')
        ->assertExitCode(0);

    @unlink(resource_path('views/components/layouts/app.blade.php'));
    @rmdir(resource_path('views/components/layouts'));
    @rmdir(resource_path('views/components'));
    @rmdir(resource_path('views'));
});

it('--patch-js injects the Sentry init block into detected entries', function (): void {
    file_put_contents(base_path('vite.config.js'), <<<'JS'
        export default {
            plugins: [
                laravel({ input: ['resources/js/app.js'] }),
            ],
        };
        JS);

    @mkdir(resource_path('js'), 0777, true);
    file_put_contents(resource_path('js/app.js'), "console.log('hi');\n");

    $this->artisan('watchtower:install', [
        '--dsn'      => 'http://abc@watchtower.test/42',
        '--patch-js' => true,
    ])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('resources/js/app.js (patched)')
        ->assertExitCode(0);

    $contents = (string) file_get_contents(resource_path('js/app.js'));

    expect($contents)->toContain('// watchtower:sentry-init')
        ->and($contents)->toContain('initWatchtower();')
        ->and($contents)->toEndWith("console.log('hi');\n");

    @unlink(resource_path('js/app.js'));
});

it('--patch-views injects the meta tags into detected layouts', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");

    @mkdir(resource_path('views/components/layouts'), 0777, true);
    file_put_contents(
        resource_path('views/components/layouts/app.blade.php'),
        "<html>\n<head>\n    <title>App</title>\n</head>\n<body>{{ \$slot }}</body>\n</html>\n"
    );

    $this->artisan('watchtower:install', [
        '--dsn'         => 'http://abc@watchtower.test/42',
        '--patch-views' => true,
    ])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('app.blade.php (patched)')
        ->assertExitCode(0);

    $contents = (string) file_get_contents(resource_path('views/components/layouts/app.blade.php'));

    expect($contents)->toContain('{{-- watchtower:user-meta --}}')
        ->and($contents)->toContain('@watchtowerUser');

    @unlink(resource_path('views/components/layouts/app.blade.php'));
    @rmdir(resource_path('views/components/layouts'));
    @rmdir(resource_path('views/components'));
    @rmdir(resource_path('views'));
});

it('prints a Filament render-hook block when a panel provider exists', function (): void {
    file_put_contents(base_path('vite.config.js'), "export default {};\n");

    @mkdir(base_path('app/Providers/Filament'), 0777, true);
    file_put_contents(base_path('app/Providers/Filament/AdminPanelProvider.php'), "<?php\n");

    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'yes')
        ->expectsOutputToContain('Detected Filament panel providers (1)')
        ->expectsOutputToContain('AdminPanelProvider.php')
        ->expectsOutputToContain("renderHook(")
        ->assertExitCode(0);

    @unlink(base_path('app/Providers/Filament/AdminPanelProvider.php'));
    @rmdir(base_path('app/Providers/Filament'));
    @rmdir(base_path('app/Providers'));
    @rmdir(base_path('app'));
});
