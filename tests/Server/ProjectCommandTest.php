<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Server\Models\Project;

it('reports an empty project table', function (): void {
    $this->artisan('watchtower:project')
        ->expectsOutputToContain('No projects yet.')
        ->assertExitCode(0);
});

it('creates a project and prints its DSN', function (): void {
    config()->set('app.url', 'https://host.test');

    $this->artisan('watchtower:project', ['action' => 'create', 'name' => 'Billing API'])
        ->assertExitCode(0);

    $project = Project::query()->firstOrFail();

    expect($project->name)->toBe('Billing API')
        ->and($project->slug)->toBe('billing-api')
        ->and($project->platform)->toBe('laravel')
        ->and($project->is_active)->toBeTrue()
        ->and($project->buildDsn('https://host.test'))
        ->toBe("https://{$project->public_key}@host.test/watchtower/{$project->getKey()}");
});

it('rejects an unknown platform', function (): void {
    $this->artisan('watchtower:project', ['action' => 'create', 'name' => 'X', '--platform' => 'cobol'])
        ->expectsOutputToContain('Unknown platform [cobol]')
        ->assertExitCode(1);

    expect(Project::query()->count())->toBe(0);
});

it('lists projects with a masked key', function (): void {
    $project = makeWatchtowerProject(['name' => 'Storefront']);

    // One assertion per run: PendingCommand matches substrings against single
    // writes, and Mockery only satisfies the first matcher per call — the whole
    // table row is one write.
    $masked = substr($project->public_key, 0, 4).'********'.substr($project->public_key, -4);

    $this->artisan('watchtower:project', ['action' => 'list'])
        ->expectsOutputToContain('Storefront')
        ->assertExitCode(0);

    $this->artisan('watchtower:project', ['action' => 'list'])
        ->expectsOutputToContain($masked)
        ->assertExitCode(0);

    $this->artisan('watchtower:project', ['action' => 'list'])
        ->expectsOutputToContain($project->buildDsn((string) config('app.url')))
        ->assertExitCode(0);
});

it('rotates a project key', function (): void {
    $project = makeWatchtowerProject();
    $original = $project->public_key;

    $this->artisan('watchtower:project', ['action' => 'rotate-key', 'name' => (string) $project->getKey()])
        ->assertExitCode(0);

    expect($project->refresh()->public_key)->not->toBe($original);
});

it('activates and deactivates a project by slug', function (): void {
    $project = makeWatchtowerProject(['slug' => 'shop']);

    $this->artisan('watchtower:project', ['action' => 'deactivate', 'name' => 'shop'])->assertExitCode(0);
    expect($project->refresh()->is_active)->toBeFalse();

    $this->artisan('watchtower:project', ['action' => 'activate', 'name' => 'shop'])->assertExitCode(0);
    expect($project->refresh()->is_active)->toBeTrue();
});

it('fails when the project cannot be found', function (): void {
    $this->artisan('watchtower:project', ['action' => 'rotate-key', 'name' => 'nope'])
        ->expectsOutputToContain('No project matches [nope]')
        ->assertExitCode(1);
});

it('rejects an unknown action', function (): void {
    $this->artisan('watchtower:project', ['action' => 'explode'])
        ->expectsOutputToContain('Unknown action [explode]')
        ->assertExitCode(1);
});
