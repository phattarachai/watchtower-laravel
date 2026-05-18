<?php

declare(strict_types=1);

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
});

it('writes env keys and patches bootstrap/app.php', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->assertExitCode(0);

    $env       = (string) file_get_contents($this->envPath);
    $bootstrap = (string) file_get_contents($this->bootstrapPath);

    expect($env)->toContain('WATCHTOWER_DSN=http://abc@watchtower.test/42')
        ->and($env)->toContain('SENTRY_LARAVEL_DSN=http://abc@watchtower.test/42');

    expect($bootstrap)->toContain('use Sentry\\Laravel\\Integration;')
        ->and($bootstrap)->toContain('Integration::handles($exceptions);');
});

it('is idempotent on a second run', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])->assertExitCode(0);
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])->assertExitCode(0);

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
    ])->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toBe($envBefore);
    expect(file_get_contents($this->bootstrapPath))->toBe($bootstrapBefore);
});

it('fails on invalid DSN', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://no-project@watchtower.test/'])
        ->assertExitCode(1);
});

it('prints the manual claude mcp add command when claude is not on PATH', function (): void {
    $this->artisan('watchtower:install', ['--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsOutputToContain('Claude Code CLI (claude) not detected on PATH.')
        ->expectsOutputToContain('claude mcp add watchtower http://watchtower.test/mcp --header "Authorization: Bearer abc"')
        ->assertExitCode(0);
});

it('skips the MCP step entirely when --no-mcp is passed', function (): void {
    $this->artisan('watchtower:install', [
        '--dsn'    => 'http://abc@watchtower.test/42',
        '--no-mcp' => true,
    ])
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
        ->expectsOutputToContain('Would run: claude mcp add watchtower http://watchtower.test/mcp')
        ->assertExitCode(0);

    expect(app(ClaudeMcpRegistrar::class)->registerCalled)->toBeFalse();
});
