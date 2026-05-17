<?php

declare(strict_types=1);

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
