<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Alerts;

use Phattarachai\WatchtowerCore\Alerts\EventSnapshot;
use Phattarachai\WatchtowerCore\Alerts\IssueSnapshot;
use Phattarachai\WatchtowerCore\Alerts\RuleSpec;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

/**
 * Projects the embedded Eloquent models onto the framework-agnostic DTOs the
 * core alert engine and the alert mailable consume.
 */
final class AlertSnapshotFactory
{
    public static function ruleSpec(AlertRule $rule): RuleSpec
    {
        return new RuleSpec(
            id: (int) $rule->id,
            name: (string) $rule->name,
            type: $rule->type,
            environment: $rule->environment,
            minLevel: (string) ($rule->min_level ?? 'error'),
            thresholdCount: $rule->threshold_count,
            thresholdWindowSeconds: $rule->threshold_window_seconds,
            cooldownSeconds: (int) ($rule->cooldown_seconds ?? 0),
            mutedUntil: $rule->muted_until?->toDateTimeImmutable(),
            isActive: (bool) $rule->is_active,
            targets: (array) ($rule->targets ?? []),
        );
    }

    public static function issue(IssueGroup $group): IssueSnapshot
    {
        return new IssueSnapshot(
            id: (int) $group->id,
            projectId: (int) $group->project_id,
            title: (string) $group->title,
            level: (string) $group->level,
            status: (string) $group->status,
            fingerprint: (string) $group->fingerprint,
            eventCount: (int) $group->event_count,
            userCount: (int) $group->user_count,
            firstSeenAt: $group->first_seen_at?->toDateTimeImmutable(),
            lastSeenAt: $group->last_seen_at?->toDateTimeImmutable(),
            snoozedUntil: $group->snoozed_until?->toDateTimeImmutable(),
        );
    }

    public static function event(Event $event): EventSnapshot
    {
        return new EventSnapshot(
            id: (string) $event->id,
            level: (string) $event->level,
            environment: $event->environment,
            release: $event->release,
            receivedAt: $event->received_at->toDateTimeImmutable(),
            payload: (array) ($event->payload ?? []),
        );
    }
}
