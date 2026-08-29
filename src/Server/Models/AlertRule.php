<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Phattarachai\WatchtowerCore\Alerts\AlertType;
use Phattarachai\WatchtowerCore\Alerts\RuleSpec;
use Phattarachai\WatchtowerLaravel\Server\Alerts\AlertSnapshotFactory;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property AlertType $type
 * @property string|null $environment
 * @property string $min_level
 * @property int|null $threshold_count
 * @property int|null $threshold_window_seconds
 * @property array<string, mixed> $targets
 * @property int $cooldown_seconds
 * @property Carbon|null $muted_until
 * @property bool $is_active
 */
class AlertRule extends WatchtowerModel
{
    protected $table = 'watchtower_alert_rules';

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationSent::class, 'rule_id');
    }

    public function toSpec(): RuleSpec
    {
        return AlertSnapshotFactory::ruleSpec($this);
    }

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'targets' => 'array',
            'muted_until' => 'datetime',
            'is_active' => 'boolean',
            'threshold_count' => 'integer',
            'threshold_window_seconds' => 'integer',
            'cooldown_seconds' => 'integer',
        ];
    }
}
