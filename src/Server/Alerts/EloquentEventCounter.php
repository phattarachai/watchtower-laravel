<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Alerts;

use DateTimeImmutable;
use Phattarachai\WatchtowerCore\Alerts\Contracts\EventCounter;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;

class EloquentEventCounter implements EventCounter
{
    public function countForGroupSince(int $groupId, DateTimeImmutable $since): int
    {
        return Event::where('group_id', $groupId)
            ->where('received_at', '>=', $since)
            ->count();
    }
}
