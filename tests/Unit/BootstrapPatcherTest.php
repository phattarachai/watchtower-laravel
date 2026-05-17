<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\BootstrapPatcher;

function freshBootstrap(): string
{
    return <<<'PHP'
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
PHP;
}

it('patches a fresh bootstrap file', function (): void {
    $patched = (new BootstrapPatcher(freshBootstrap()))->patch();

    expect($patched)->not->toBeNull()
        ->and($patched)->toMatch('/^use\s+Sentry\\\\Laravel\\\\Integration;/m')
        ->and($patched)->toMatch('/Integration::handles\(\$exceptions\)/');
});

it('returns unchanged contents when already wired', function (): void {
    $source = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
PHP;

    $patcher = new BootstrapPatcher($source);

    expect($patcher->alreadyWired())->toBeTrue()
        ->and($patcher->patch())->toBe($source);
});

it('returns null when withExceptions block is missing', function (): void {
    $source = <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))->create();
PHP;

    expect((new BootstrapPatcher($source))->patch())->toBeNull();
});

it('matches withExceptions with void return type and use clause', function (): void {
    $source = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

$shared = 'something';

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions) use ($shared): void {
        //
    })->create();
PHP;

    $patched = (new BootstrapPatcher($source))->patch();

    expect($patched)->not->toBeNull()
        ->and($patched)->toMatch('/Integration::handles\(\$exceptions\)/')
        ->and($patched)->toMatch('/^use\s+Sentry\\\\Laravel\\\\Integration;/m');
});

it('does not duplicate the use statement when already present', function (): void {
    $source = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
PHP;

    $patched = (new BootstrapPatcher($source))->patch();

    expect($patched)->not->toBeNull();
    expect(substr_count((string) $patched, 'use Sentry\\Laravel\\Integration;'))->toBe(1);
    expect($patched)->toMatch('/Integration::handles\(\$exceptions\)/');
});
