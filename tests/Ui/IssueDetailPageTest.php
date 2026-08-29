<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

use function Pest\Laravel\actingAs;

it('renders the latest event in full, with the recent-event list and navigation', function (): void {
    $project = makeWatchtowerProject();
    $group = makeWatchtowerGroup($project);

    $older = makeWatchtowerEvent($group, ['received_at' => now()->subHours(2), 'environment' => 'staging']);
    $latest = makeWatchtowerEvent($group, ['received_at' => now()]);

    actingAs(wtUser())
        ->get(route('watchtower.ui.issue', ['group' => $group->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Watchtower')
            ->where('view', 'issue')
            ->where('issue.id', $group->getKey())
            ->has('issue.fingerprint')
            ->where('event.id', $latest->getKey())
            ->where('event.exception.type', 'RuntimeException')
            ->has('event.stacktrace', 2)
            ->where('event.stacktrace.1.context_line', '        throw new RuntimeException("Something exploded");')
            ->where('event.request.method', 'POST')
            ->has('event.breadcrumbs', 1)
            ->where('event.user.email', 'buyer@example.test')
            ->where('event.tags.route', 'checkout.store')
            ->has('event.contexts.runtime')
            ->has('events', 2)
            ->where('events.1.environment', 'staging')
            ->where('navigation.prev', null)
            ->where('navigation.next', $older->getKey())
            ->where('navigation.position', 1)
            ->where('navigation.total', 2));
});

it('opens a specific event through ?event= and walks back to the newer one', function (): void {
    $project = makeWatchtowerProject();
    $group = makeWatchtowerGroup($project);

    $older = makeWatchtowerEvent($group, ['received_at' => now()->subHours(2)]);
    $latest = makeWatchtowerEvent($group, ['received_at' => now()]);

    actingAs(wtUser())
        ->get(route('watchtower.ui.issue', ['group' => $group->getKey(), 'event' => $older->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('event.id', $older->getKey())
            ->where('navigation.prev', $latest->getKey())
            ->where('navigation.next', null)
            ->where('navigation.position', 2)
            ->etc());
});

it('404s an event that belongs to a different issue', function (): void {
    $project = makeWatchtowerProject();
    $group = makeWatchtowerGroup($project);
    $foreign = makeWatchtowerEvent(makeWatchtowerGroup($project));

    actingAs(wtUser())
        ->get(route('watchtower.ui.issue', ['group' => $group->getKey(), 'event' => $foreign->getKey()]))
        ->assertNotFound();
});

it('renders an issue that has no events yet', function (): void {
    $group = makeWatchtowerGroup(makeWatchtowerProject());

    actingAs(wtUser())
        ->get(route('watchtower.ui.issue', ['group' => $group->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('event', null)
            ->has('events', 0)
            ->where('navigation.total', 0)
            ->etc());
});

it('round-trips a status change and clears the snooze when resolving', function (): void {
    $group = makeWatchtowerGroup(makeWatchtowerProject());

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.status', ['group' => $group->getKey()]), [
            'status' => 'snoozed',
            'snooze_minutes' => 120,
        ])
        ->assertOk()
        ->assertJsonPath('issue.status', 'snoozed');

    expect($group->refresh()->snoozed_until)->not->toBeNull();

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.status', ['group' => $group->getKey()]), [
            'status' => IssueGroup::STATUS_RESOLVED,
        ])
        ->assertOk()
        ->assertJsonPath('issue.status', 'resolved');

    expect($group->refresh()->snoozed_until)->toBeNull()
        ->and($group->last_status_change_at)->not->toBeNull();
});

it('rejects an unknown status and a cross-project status change', function (): void {
    $group = makeWatchtowerGroup(makeWatchtowerProject());
    $other = makeWatchtowerProject();

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.status', ['group' => $group->getKey()]), ['status' => 'archived'])
        ->assertStatus(422);

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.status', ['group' => $group->getKey()]), [
            'status' => 'resolved',
            'project_id' => $other->getKey(),
        ])
        ->assertNotFound();
});
