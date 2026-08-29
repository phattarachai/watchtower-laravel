<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\NotificationSent;

it('deletes events past the global retention window and keeps recent ones', function (): void {
    config()->set('watchtower.server.retention_days', 30);

    $group = makeWatchtowerGroup(makeWatchtowerProject());
    $stale = makeWatchtowerEvent($group, ['received_at' => now()->subDays(45)]);
    $fresh = makeWatchtowerEvent($group, ['received_at' => now()->subDays(5)]);

    $this->artisan('watchtower:prune')->assertExitCode(0);

    expect(Event::query()->whereKey($stale->getKey())->exists())->toBeFalse()
        ->and(Event::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('keeps the issue group even when every one of its events is pruned', function (): void {
    config()->set('watchtower.server.retention_days', 30);

    $group = makeWatchtowerGroup(makeWatchtowerProject());
    makeWatchtowerEvent($group, ['received_at' => now()->subDays(90)]);

    $this->artisan('watchtower:prune')->assertExitCode(0);

    expect(Event::query()->count())->toBe(0)
        ->and(IssueGroup::query()->whereKey($group->getKey())->exists())->toBeTrue();
});

it('honours a per-project retention override', function (): void {
    config()->set('watchtower.server.retention_days', 90);

    $short = makeWatchtowerProject(['name' => 'Short', 'retention_days' => 7]);
    $default = makeWatchtowerProject(['name' => 'Default']);

    $shortEvent = makeWatchtowerEvent(makeWatchtowerGroup($short), ['received_at' => now()->subDays(30)]);
    $defaultEvent = makeWatchtowerEvent(makeWatchtowerGroup($default), ['received_at' => now()->subDays(30)]);

    $this->artisan('watchtower:prune')->assertExitCode(0);

    expect(Event::query()->whereKey($shortEvent->getKey())->exists())->toBeFalse()
        ->and(Event::query()->whereKey($defaultEvent->getKey())->exists())->toBeTrue();
});

it('never prunes when retention is zero or negative', function (): void {
    config()->set('watchtower.server.retention_days', 0);

    $group = makeWatchtowerGroup(makeWatchtowerProject());
    makeWatchtowerEvent($group, ['received_at' => now()->subYears(3)]);

    $this->artisan('watchtower:prune')->assertExitCode(0);

    expect(Event::query()->count())->toBe(1);
});

it('drops notification records older than 90 days', function (): void {
    $project = makeWatchtowerProject();
    $group = makeWatchtowerGroup($project);
    $rule = makeWatchtowerRule($project);

    $stale = NotificationSent::create([
        'rule_id' => $rule->getKey(),
        'group_id' => $group->getKey(),
        'kind' => 'new_issue',
        'sent_at' => now()->subDays(120),
        'recipient_count' => 1,
    ]);

    $fresh = NotificationSent::create([
        'rule_id' => $rule->getKey(),
        'group_id' => $group->getKey(),
        'kind' => 'new_issue',
        'sent_at' => now()->subDays(10),
        'recipient_count' => 1,
    ]);

    $this->artisan('watchtower:prune')->assertExitCode(0);

    expect(NotificationSent::query()->whereKey($stale->getKey())->exists())->toBeFalse()
        ->and(NotificationSent::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('schedules itself daily in embedded modes', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'watchtower:prune')))->toBeTrue();
});
