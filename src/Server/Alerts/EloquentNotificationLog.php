<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Alerts;

use DateTimeImmutable;
use Phattarachai\WatchtowerCore\Alerts\Contracts\NotificationLog;
use Phattarachai\WatchtowerLaravel\Server\Models\NotificationSent;

class EloquentNotificationLog implements NotificationLog
{
    public function hasNotified(int $ruleId, int $groupId): bool
    {
        return NotificationSent::where('rule_id', $ruleId)
            ->where('group_id', $groupId)
            ->exists();
    }

    public function hasNotifiedSince(int $ruleId, int $groupId, DateTimeImmutable $since): bool
    {
        return NotificationSent::where('rule_id', $ruleId)
            ->where('group_id', $groupId)
            ->where('sent_at', '>=', $since)
            ->exists();
    }
}
