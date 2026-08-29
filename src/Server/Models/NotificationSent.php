<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $rule_id
 * @property int $group_id
 * @property string $kind
 * @property Carbon $sent_at
 * @property int $recipient_count
 */
class NotificationSent extends WatchtowerModel
{
    public $timestamps = false;

    protected $table = 'watchtower_notifications_sent';

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(IssueGroup::class, 'group_id');
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }
}
