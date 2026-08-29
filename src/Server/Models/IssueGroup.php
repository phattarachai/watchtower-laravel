<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $fingerprint
 * @property string $title
 * @property string|null $platform
 * @property string $level
 * @property string $status
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property int $event_count
 * @property int $user_count
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $last_status_change_at
 * @property string|null $resolved_in_release
 */
class IssueGroup extends WatchtowerModel
{
    public const string STATUS_UNRESOLVED = 'unresolved';

    public const string STATUS_RESOLVED = 'resolved';

    public const string STATUS_IGNORED = 'ignored';

    public const string STATUS_SNOOZED = 'snoozed';

    protected $table = 'watchtower_issue_groups';

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'group_id');
    }

    public function uniqueUsers(): HasMany
    {
        return $this->hasMany(IssueUser::class, 'group_id');
    }

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'last_status_change_at' => 'datetime',
            'event_count' => 'integer',
            'user_count' => 'integer',
        ];
    }
}
