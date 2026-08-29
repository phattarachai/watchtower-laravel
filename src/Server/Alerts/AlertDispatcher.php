<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Alerts;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Phattarachai\WatchtowerCore\Alerts\AlertDecision;
use Phattarachai\WatchtowerCore\Alerts\AlertRuleEvaluator;
use Phattarachai\WatchtowerLaravel\Server\Mail\IssueAlertMail;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\NotificationSent;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

/**
 * Loads the project's rules, asks the core evaluator what fires, then queues
 * the mail and records the send. Recipients are the literal addresses stored on
 * the rule — the embedded server has no user directory of its own.
 */
class AlertDispatcher
{
    public function __construct(private readonly AlertRuleEvaluator $evaluator) {}

    public function dispatch(IssueGroup $group, Event $event, bool $isNewGroup, bool $isRegression): void
    {
        $rules = $this->loadActiveRules($group);
        $project = $group->project;

        if ($rules->isEmpty() || $project === null) {
            return;
        }

        $decisions = $this->evaluator->evaluate(
            AlertSnapshotFactory::issue($group),
            AlertSnapshotFactory::event($event),
            $isNewGroup,
            $isRegression,
            $rules->map(AlertSnapshotFactory::ruleSpec(...))->values()->all(),
            now()->toDateTimeImmutable(),
        );

        foreach ($decisions as $decision) {
            $this->deliver($decision, $rules->get($decision->ruleId), $project, $group, $event, $isRegression);
        }
    }

    /**
     * @return Collection<int, AlertRule>
     */
    private function loadActiveRules(IssueGroup $group): Collection
    {
        return AlertRule::where('project_id', $group->project_id)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
    }

    private function deliver(
        AlertDecision $decision,
        AlertRule $rule,
        Project $project,
        IssueGroup $group,
        Event $event,
        bool $isRegression,
    ): void {
        $emails = $this->recipients($rule);

        if ($emails === []) {
            return;
        }

        Mail::to($emails)->queue(new IssueAlertMail(
            rule: $decision->rule,
            issue: AlertSnapshotFactory::issue($group),
            event: AlertSnapshotFactory::event($event),
            project: $project,
            isRegression: $isRegression,
        ));

        NotificationSent::create([
            'rule_id' => $decision->ruleId,
            'group_id' => $group->getKey(),
            'kind' => $decision->kind->value,
            'sent_at' => now(),
            'recipient_count' => count($emails),
        ]);
    }

    /**
     * @return list<string>
     */
    private function recipients(AlertRule $rule): array
    {
        $raw = $rule->targets['emails'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $emails = array_map(
            fn (mixed $email): string => strtolower(trim(is_scalar($email) ? (string) $email : '')),
            $raw,
        );

        return array_values(array_unique(array_filter(
            $emails,
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
