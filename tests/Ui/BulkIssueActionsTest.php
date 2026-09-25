<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

use function Pest\Laravel\actingAs;

it('resolves every selected issue and leaves the rest alone', function (): void {
    $project = makeWatchtowerProject();
    $first = makeWatchtowerGroup($project, ['status' => 'snoozed', 'snoozed_until' => now()->addHour()]);
    $second = makeWatchtowerGroup($project);
    $untouched = makeWatchtowerGroup($project);

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.bulk-status'), [
            'ids' => [$first->getKey(), $second->getKey()],
            'status' => IssueGroup::STATUS_RESOLVED,
        ])
        ->assertOk()
        ->assertJsonPath('updated', 2);

    expect($first->refresh()->status)->toBe('resolved')
        ->and($first->snoozed_until)->toBeNull()
        ->and($first->last_status_change_at)->not->toBeNull()
        ->and($second->refresh()->status)->toBe('resolved')
        ->and($untouched->refresh()->status)->toBe('unresolved');
});

it('snoozes the selection for the chosen duration', function (): void {
    $group = makeWatchtowerGroup(makeWatchtowerProject());

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.bulk-status'), [
            'ids' => [$group->getKey()],
            'status' => IssueGroup::STATUS_SNOOZED,
            'snooze_minutes' => 10080,
        ])
        ->assertOk();

    expect($group->refresh()->status)->toBe('snoozed')
        ->and($group->snoozed_until->greaterThan(now()->addDays(6)))->toBeTrue();
});

it('deletes the selected issues together with their events', function (): void {
    $project = makeWatchtowerProject();
    $doomed = makeWatchtowerGroup($project);
    makeWatchtowerEvent($doomed);
    $kept = makeWatchtowerGroup($project);
    makeWatchtowerEvent($kept);

    actingAs(wtUser())
        ->deleteJson(route('watchtower.ui.issues.bulk-destroy'), ['ids' => [$doomed->getKey(), 999999]])
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    expect(IssueGroup::query()->whereKey($doomed->getKey())->exists())->toBeFalse()
        ->and(Event::query()->where('group_id', $doomed->getKey())->exists())->toBeFalse()
        ->and(Event::query()->where('group_id', $kept->getKey())->exists())->toBeTrue();
});

it('rejects an empty selection, an unknown status and a missing status', function (): void {
    $group = makeWatchtowerGroup(makeWatchtowerProject());

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.bulk-status'), ['ids' => [], 'status' => 'resolved'])
        ->assertStatus(422);

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.bulk-status'), ['ids' => [$group->getKey()], 'status' => 'archived'])
        ->assertStatus(422);

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.bulk-status'), ['ids' => [$group->getKey()]])
        ->assertStatus(422);
});

it('hands the bulk endpoints to the inbox', function (): void {
    actingAs(wtUser())
        ->get(route('watchtower.ui.issues'))
        ->assertInertia(fn ($page) => $page
            ->has('endpoints.issueBulkStatus')
            ->has('endpoints.issueBulkDestroy')
            ->etc());
});
