<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $group_id
 * @property int $project_id
 * @property string|null $environment
 * @property string|null $release
 * @property Carbon $received_at
 * @property string $level
 * @property string|null $sdk_name
 * @property string|null $user_id_hash
 * @property array<string, mixed> $payload
 */
class Event extends WatchtowerModel
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'watchtower_events';

    public function group(): BelongsTo
    {
        return $this->belongsTo(IssueGroup::class, 'group_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
