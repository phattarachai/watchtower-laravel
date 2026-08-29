<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ui;

use Phattarachai\WatchtowerCore\Alerts\AlertType;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;

final class AlertRulePresenter
{
    /** @var list<string> */
    public const array LEVELS = ['debug', 'info', 'warning', 'error', 'fatal'];

    /**
     * @return array<string, mixed>
     */
    public static function props(): array
    {
        return [
            'rules' => AlertRule::query()
                ->with('project')
                ->orderBy('project_id')
                ->orderBy('name')
                ->get()
                ->map(self::rule(...))
                ->all(),
            'projects' => ProjectPresenter::options(),
            'options' => [
                'types' => array_map(
                    fn (AlertType $type): array => ['value' => $type->value, 'label' => self::typeLabel($type)],
                    AlertType::cases(),
                ),
                'levels' => self::LEVELS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rule(AlertRule $rule): array
    {
        return [
            'id' => (int) $rule->getKey(),
            'project_id' => (int) $rule->project_id,
            'project' => $rule->project === null ? null : [
                'id' => (int) $rule->project->getKey(),
                'name' => (string) $rule->project->name,
            ],
            'name' => (string) $rule->name,
            'type' => $rule->type->value,
            'environment' => $rule->environment,
            'min_level' => (string) $rule->min_level,
            'threshold_count' => $rule->threshold_count,
            'threshold_window_seconds' => $rule->threshold_window_seconds,
            'cooldown_seconds' => (int) $rule->cooldown_seconds,
            'emails' => self::emails($rule),
            'is_active' => (bool) $rule->is_active,
            'muted_until' => $rule->muted_until?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function emails(AlertRule $rule): array
    {
        $raw = $rule->targets['emails'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $email): string => is_scalar($email) ? (string) $email : '',
            $raw,
        ));
    }

    private static function typeLabel(AlertType $type): string
    {
        return match ($type) {
            AlertType::NewIssue => 'New issue',
            AlertType::Regression => 'Regression',
            AlertType::Threshold => 'Threshold spike',
            AlertType::Milestone => 'Milestone',
        };
    }
}
