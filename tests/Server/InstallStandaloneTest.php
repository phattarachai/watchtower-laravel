<?php

declare(strict_types=1);

use Illuminate\Testing\PendingCommand;
use Phattarachai\WatchtowerLaravel\Console\InstallCommand;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Ui\UiPage;
use Phattarachai\WatchtowerLaravel\Support\ClaudeMcpRegistrar;

beforeEach(function (): void {
    config()->set('app.name', 'Embedded Host');
    config()->set('app.url', 'https://host.test');

    $this->envPath = base_path('.env');
    $this->originalEnv = is_file($this->envPath) ? file_get_contents($this->envPath) : null;
    file_put_contents($this->envPath, "APP_NAME=Testing\n");

    @mkdir(base_path('bootstrap'), 0777, true);
    $this->bootstrapPath = base_path('bootstrap/app.php');
    $this->originalBootstrap = is_file($this->bootstrapPath) ? file_get_contents($this->bootstrapPath) : null;
    file_put_contents($this->bootstrapPath, <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
PHP);

    @mkdir(resource_path('css'), 0777, true);
    file_put_contents(base_path('vite.config.js'), "export default {\n    plugins: [],\n};\n");
    file_put_contents(resource_path('css/app.css'), "@import 'tailwindcss';\n");

    $this->app->singleton(ClaudeMcpRegistrar::class, fn (): ClaudeMcpRegistrar => new class extends ClaudeMcpRegistrar
    {
        public function find(): ?string
        {
            return null;
        }
    });
});

afterEach(function (): void {
    $this->originalEnv === null
        ? @unlink($this->envPath)
        : file_put_contents($this->envPath, $this->originalEnv);

    $this->originalBootstrap === null
        ? @unlink($this->bootstrapPath)
        : file_put_contents($this->bootstrapPath, $this->originalBootstrap);

    @unlink(base_path('vite.config.js'));
    @unlink(resource_path('css/app.css'));
    @unlink(resource_path('css/watchtower.css'));
    @rmdir(resource_path('css'));
    @unlink(resource_path('js/pages/Watchtower.jsx'));
    @unlink(resource_path('js/pages/Watchtower.tsx'));
    @unlink(base_path('resources/js/app.tsx'));
    @unlink(resource_path('js/vendor/watchtower.js'));
    @unlink(resource_path('js/vendor/livewire.js'));
    @unlink(resource_path('js/vendor/noise.js'));
    @rmdir(resource_path('js/pages'));
    @rmdir(resource_path('js/vendor'));
    @rmdir(resource_path('js'));
    @unlink(config_path('watchtower.php'));
});

function runStandaloneInstall(array $options = []): PendingCommand
{
    return test()->artisan('watchtower:install', ['--standalone' => true, ...$options])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'no');
}

it('prints the plan without touching anything in dry-run mode', function (): void {
    $envBefore = (string) file_get_contents($this->envPath);
    $viteBefore = (string) file_get_contents(base_path('vite.config.js'));

    runStandaloneInstall(['--dry-run' => true])
        ->expectsOutputToContain('Would set in .env: WATCHTOWER_MODE=standalone')
        ->expectsOutputToContain('Would run: php artisan migrate --force')
        ->expectsOutputToContain('Would create the first watchtower_projects row: Embedded Host')
        ->expectsOutputToContain('Would publish: resources/js/pages/Watchtower.jsx')
        ->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toBe($envBefore)
        ->and(file_get_contents(base_path('vite.config.js')))->toBe($viteBefore)
        ->and(Project::query()->count())->toBe(0);
});

it('writes env keys, creates the first project and patches the host build files', function (): void {
    runStandaloneInstall()->assertExitCode(0);

    $project = Project::query()->firstOrFail();
    $env = (string) file_get_contents($this->envPath);

    expect($env)->toContain('WATCHTOWER_MODE=standalone')
        ->and($env)->toContain('SENTRY_LARAVEL_DSN='.$project->buildDsn('https://host.test'))
        ->and($env)->toContain('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=true')
        ->and($project->name)->toBe('Embedded Host')
        ->and($project->platform)->toBe('laravel')
        ->and($project->slug)->toBe('embedded-host');

    expect((string) file_get_contents($this->bootstrapPath))
        ->toContain('Integration::handles($exceptions);');

    expect((string) file_get_contents(base_path('vite.config.js')))->toContain("'@watchtower'");

    expect((string) file_get_contents(resource_path('css/watchtower.css')))
        ->toContain("@import 'tailwindcss' prefix(tw) source(none);")
        ->toContain("@source '../../vendor/phattarachai/watchtower-laravel/resources/js/watchtower/**/*.jsx';");

    expect((string) file_get_contents(resource_path('css/app.css')))->not->toContain('prefix(tw)');

    expect(is_file(resource_path('js/pages/Watchtower.jsx')))->toBeTrue();

    expect($project->mcp_token_hash)->not->toBeNull();
});

it('publishes the page stub as .tsx for a TypeScript host', function (): void {
    @mkdir(resource_path('js'), 0777, true);
    file_put_contents(resource_path('js/app.tsx'), "// tsx host\n");

    runStandaloneInstall()->assertExitCode(0);

    expect(is_file(resource_path('js/pages/Watchtower.tsx')))->toBeTrue()
        ->and(is_file(resource_path('js/pages/Watchtower.jsx')))->toBeFalse()
        ->and((string) file_get_contents(resource_path('js/pages/Watchtower.tsx')))
        ->toContain("from '@watchtower'");

    @unlink(resource_path('js/app.tsx'));
});

it('re-publishes the page over the existing .tsx in a TypeScript host', function (): void {
    @mkdir(resource_path('js/pages'), 0777, true);
    file_put_contents(resource_path('js/app.tsx'), "// tsx host\n");
    file_put_contents(resource_path('js/pages/Watchtower.tsx'), "// 1.4 stub\n");
    // The publish destination is read when the provider boots, as in a real host.
    $this->refreshApplication();

    $this->artisan('vendor:publish', ['--tag' => 'watchtower-inertia', '--force' => true])->assertExitCode(0);

    expect(is_file(resource_path('js/pages/Watchtower.jsx')))->toBeFalse()
        ->and((string) file_get_contents(resource_path('js/pages/Watchtower.tsx')))
        ->toBe((string) file_get_contents(__DIR__.'/../../resources/js/pages/Watchtower.jsx'));
});

it('never prompts for an upstream DSN in standalone mode', function (): void {
    runStandaloneInstall()
        ->expectsOutputToContain('Wrote SENTRY_LARAVEL_DSN to .env')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->envPath))->not->toContain('WATCHTOWER_DSN=');
});

it('prints the auth-gate snippet and the next steps', function (): void {
    runStandaloneInstall()
        ->expectsOutputToContain('Watchtower::auth(')
        ->expectsOutputToContain("Gate::define('viewWatchtower'")
        ->expectsOutputToContain('Next steps:')
        ->expectsOutputToContain('Visit /watchtower')
        ->assertExitCode(0);
});

it('is idempotent on a second run', function (): void {
    runStandaloneInstall()->assertExitCode(0);

    $project = Project::query()->firstOrFail();

    runStandaloneInstall()
        ->expectsOutputToContain('already exists — reusing it')
        ->assertExitCode(0);

    expect(Project::query()->count())->toBe(1)
        ->and(Project::query()->firstOrFail()->public_key)->toBe($project->public_key)
        ->and(Project::query()->firstOrFail()->mcp_token_hash)->toBe($project->mcp_token_hash);

    $vite = (string) file_get_contents(base_path('vite.config.js'));
    $css = (string) file_get_contents(resource_path('css/watchtower.css'));

    expect(substr_count($vite, '@watchtower'))->toBe(1)
        ->and(substr_count($vite, "import path from 'node:path';"))->toBeLessThanOrEqual(1)
        ->and(substr_count($css, 'prefix(tw)'))->toBe(1)
        ->and(substr_count($css, 'watchtower-laravel/resources/js/watchtower'))->toBe(1);
});

it('skips the UI files and says to install Inertia when the host has none', function (): void {
    $realInertiaClass = UiPage::$inertiaClass;
    UiPage::$inertiaClass = 'Inertia\NotInstalled';

    try {
        runStandaloneInstall()
            ->expectsOutputToContain('composer require inertiajs/inertia-laravel')
            ->doesntExpectOutputToContain('Visit /watchtower')
            ->assertExitCode(0);
    } finally {
        UiPage::$inertiaClass = $realInertiaClass;
    }

    expect(Project::query()->count())->toBe(1)
        ->and(is_file(resource_path('js/pages/Watchtower.jsx')))->toBeFalse()
        ->and(is_file(resource_path('css/watchtower.css')))->toBeFalse()
        ->and((string) file_get_contents(base_path('vite.config.js')))->not->toContain('@watchtower');
});

it('keeps the upstream DSN flow for --mode=dual', function (): void {
    test()->artisan('watchtower:install', ['--mode' => 'dual', '--dsn' => 'http://abc@watchtower.test/42'])
        ->expectsConfirmation(InstallCommand::PII_CONFIRM_QUESTION, 'no')
        ->assertExitCode(0);

    $env = (string) file_get_contents($this->envPath);

    expect($env)->toContain('WATCHTOWER_MODE=dual')
        ->and($env)->toContain('WATCHTOWER_DSN=http://abc@watchtower.test/42');
});

it('rejects an unknown mode', function (): void {
    test()->artisan('watchtower:install', ['--mode' => 'sideways'])
        ->expectsOutputToContain('Unknown mode [sideways]')
        ->assertExitCode(1);
});
