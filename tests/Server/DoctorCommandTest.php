<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Support\EmbeddedUiPatcher;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

beforeEach(function (): void {
    @mkdir(resource_path('css'), 0777, true);
    @mkdir(resource_path('js/pages'), 0777, true);
});

afterEach(function (): void {
    @unlink(base_path('vite.config.js'));
    @unlink(resource_path('css/app.css'));
    @unlink(resource_path('css/watchtower.css'));
    @unlink(resource_path('js/pages/Watchtower.jsx'));
    @rmdir(resource_path('css'));
    @rmdir(resource_path('js/pages'));
    @rmdir(resource_path('js'));
});

function wireDoctorFixture(): void
{
    makeWatchtowerProject();

    file_put_contents(base_path('vite.config.js'), <<<'JS'
        export default {
            resolve: {
                alias: {
                    '@watchtower': './vendor/phattarachai/watchtower-laravel/resources/js/watchtower',
                },
            },
        };
        JS);

    file_put_contents(resource_path('css/watchtower.css'), EmbeddedUiPatcher::cssContents());

    file_put_contents(resource_path('js/pages/Watchtower.jsx'), "export default function Watchtower() {}\n");
}

it('passes on a fully wired standalone install', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    wireDoctorFixture();

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('Watchtower looks correctly installed.')
        ->assertExitCode(0);
});

it('flags a missing Inertia page stub', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    wireDoctorFixture();
    @unlink(resource_path('js/pages/Watchtower.jsx'));

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('vendor:publish --tag=watchtower-inertia')
        ->assertExitCode(1);
});

it('flags a missing @watchtower Vite alias', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    wireDoctorFixture();
    file_put_contents(base_path('vite.config.js'), "export default {};\n");

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('Add a resolve.alias entry for @watchtower')
        ->assertExitCode(1);
});

it('flags a Tailwind entry without prefix(tw)', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    wireDoctorFixture();
    file_put_contents(resource_path('css/watchtower.css'), EmbeddedUiPatcher::TAILWIND_SOURCE."\n");

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain("@import 'tailwindcss' prefix(tw) source(none);")
        ->assertExitCode(1);
});

it('flags an install with no active project', function (): void {
    config()->set('watchtower.server.self_capture', 'loopback');
    wireDoctorFixture();
    Project::query()->update(['is_active' => false]);

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('No active project.')
        ->assertExitCode(1);
});

it('passes the self-capture check when the in-process transport is bound', function (): void {
    wireDoctorFixture();
    config()->set('sentry.dsn', Project::query()->firstOrFail()->buildDsn('https://host.test'));
    $this->app->register(SentryServiceProvider::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('Watchtower looks correctly installed.')
        ->assertExitCode(0);
});

it('fails the self-capture check when no Sentry client is bound', function (): void {
    wireDoctorFixture();
    SentrySdk::setCurrentHub(new Hub);

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('No Sentry client is bound')
        ->assertExitCode(1);
});

it('rejects an unknown mode', function (): void {
    config()->set('watchtower.mode', 'sideways');

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('watchtower.mode is [sideways]')
        ->assertExitCode(1);
});

it('runs the relay checks in relay mode', function (): void {
    config()->set('watchtower.mode', 'relay');

    $this->artisan('watchtower:doctor')
        ->doesntExpectOutputToContain('Tables migrated')
        ->assertExitCode(0);
});

it('flags a missing DSN in relay mode', function (): void {
    config()->set('watchtower.mode', 'relay');
    config()->set('watchtower.dsn', null);

    $this->artisan('watchtower:doctor')
        ->expectsOutputToContain('watchtower.dsn is missing or malformed')
        ->assertExitCode(1);
});
