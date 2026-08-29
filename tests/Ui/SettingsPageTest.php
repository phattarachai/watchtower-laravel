<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Server\Models\Project;

use function Pest\Laravel\actingAs;

it('shows every project with its DSN, masked key and install snippets', function (): void {
    $project = makeWatchtowerProject(['name' => 'Storefront']);

    actingAs(wtUser())
        ->get(route('watchtower.ui.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Watchtower')
            ->where('view', 'settings')
            ->has('projects', 1)
            ->where('projects.0.name', 'Storefront')
            ->where('projects.0.dsn', "https://{$project->public_key}@apps.test/watchtower/{$project->getKey()}")
            ->where('projects.0.masked_key', fn (string $masked): bool => ! str_contains($masked, substr((string) $project->public_key, 8, 8)))
            ->has('projects.0.snippets', 4)
            ->where('projects.0.snippets.0.key', 'laravel')
            ->where('projects.0.snippets.0.code', fn (string $code): bool => str_contains($code, 'WATCHTOWER_DSN='))
            ->where('projects.0.snippets.1.code', fn (string $code): bool => str_contains($code, 'tunnel:'))
            ->has('platforms')
            ->has('endpoints.projectStore'));
});

it('creates a project with a slug and a fresh public key', function (): void {
    $response = actingAs(wtUser())
        ->postJson(route('watchtower.ui.projects.store'), ['name' => 'Warehouse App', 'platform' => 'nextjs'])
        ->assertCreated()
        ->assertJsonPath('project.name', 'Warehouse App')
        ->assertJsonPath('project.slug', 'warehouse-app')
        ->assertJsonPath('project.platform', 'nextjs');

    $project = Project::query()->find($response->json('project.id'));

    expect($project->public_key)->toHaveLength(32)
        ->and($response->json('project.dsn'))->toContain($project->public_key);
});

it('rejects an unknown platform', function (): void {
    actingAs(wtUser())
        ->postJson(route('watchtower.ui.projects.store'), ['name' => 'Nope', 'platform' => 'cobol'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

it('renames, disables and rotates the key of a project', function (): void {
    $project = makeWatchtowerProject(['name' => 'Storefront']);
    $original = $project->public_key;

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.projects.update', ['project' => $project->getKey()]), [
            'name' => 'Storefront EU',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('project.name', 'Storefront EU')
        ->assertJsonPath('project.is_active', false);

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.projects.rotate', ['project' => $project->getKey()]))
        ->assertOk();

    expect($project->refresh()->public_key)->not->toBe($original)->toHaveLength(32);
});

it('makes a unique slug when two projects share a name', function (): void {
    makeWatchtowerProject(['name' => 'Twin', 'slug' => 'twin']);

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.projects.store'), ['name' => 'Twin', 'platform' => 'laravel'])
        ->assertCreated()
        ->assertJsonPath('project.slug', fn (string $slug): bool => $slug !== 'twin' && str_starts_with($slug, 'twin-'));
});
