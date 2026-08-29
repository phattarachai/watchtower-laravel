<?php

declare(strict_types=1);

use function Pest\Laravel\actingAs;

it('renders the issue inbox with filters, counts and pagination', function (): void {
    $project = makeWatchtowerProject(['name' => 'Storefront']);
    $other = makeWatchtowerProject(['name' => 'Warehouse']);

    $group = makeWatchtowerGroup($project, ['title' => 'Payment gateway timeout', 'last_seen_at' => now()]);
    makeWatchtowerEvent($group);
    makeWatchtowerGroup($other, ['title' => 'Stock sync failed', 'last_seen_at' => now()->subHour()]);

    actingAs(wtUser())
        ->get(route('watchtower.ui.issues'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Watchtower')
            ->where('view', 'issues')
            ->has('endpoints.issues')
            ->has('endpoints.issueStatus')
            ->has('endpoints.alertStore')
            ->has('endpoints.projectRotateKey')
            ->has('brand.version')
            ->has('projects', 2)
            ->where('environments', ['production'])
            ->where('counts.all', 2)
            ->where('counts.unresolved', 2)
            ->where('counts.resolved', 0)
            ->has('issues.data', 2)
            ->where('issues.data.0.title', 'Payment gateway timeout')
            ->where('issues.data.0.project.name', 'Storefront')
            ->has('issues.data.0.event_count')
            ->has('issues.data.0.last_seen_at')
            ->where('issues.meta.per_page', 25)
            ->where('issues.meta.total', 2));
});

it('narrows the list by project, status, level, environment and search', function (): void {
    $project = makeWatchtowerProject(['name' => 'Storefront']);
    $other = makeWatchtowerProject(['name' => 'Warehouse']);

    $matching = makeWatchtowerGroup($project, ['title' => 'Payment gateway timeout', 'level' => 'fatal']);
    makeWatchtowerEvent($matching, ['environment' => 'staging']);

    $noise = makeWatchtowerGroup($other, ['title' => 'Stock sync failed', 'status' => 'resolved']);
    makeWatchtowerEvent($noise, ['environment' => 'production']);

    $get = fn (array $query) => actingAs(wtUser())->get(route('watchtower.ui.issues', $query));

    $get(['project_id' => $project->getKey()])
        ->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $matching->getKey())->etc());

    $get(['status' => 'resolved'])
        ->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $noise->getKey())->etc());

    $get(['level' => 'fatal'])
        ->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $matching->getKey())->etc());

    $get(['environment' => 'staging'])
        ->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $matching->getKey())->etc());

    $get(['q' => 'gateway'])
        ->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.id', $matching->getKey())->etc());

    $get(['status' => 'nonsense'])
        ->assertInertia(fn ($page) => $page->where('filters.status', null)->has('issues.data', 2)->etc());
});

it('paginates at 25 issues per page, newest last-seen first', function (): void {
    $project = makeWatchtowerProject();

    foreach (range(1, 30) as $index) {
        makeWatchtowerGroup($project, [
            'title' => "Issue {$index}",
            'last_seen_at' => now()->subMinutes(30 - $index),
        ]);
    }

    actingAs(wtUser())
        ->get(route('watchtower.ui.issues'))
        ->assertInertia(fn ($page) => $page
            ->has('issues.data', 25)
            ->where('issues.data.0.title', 'Issue 30')
            ->where('issues.meta.last_page', 2)
            ->where('issues.meta.total', 30)
            ->etc());

    actingAs(wtUser())
        ->get(route('watchtower.ui.issues', ['page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('issues.data', 5)
            ->where('issues.meta.current_page', 2)
            ->etc());
});
