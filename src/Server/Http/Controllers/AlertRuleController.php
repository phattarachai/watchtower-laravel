<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Phattarachai\WatchtowerLaravel\Server\Alerts\AlertSnapshotFactory;
use Phattarachai\WatchtowerLaravel\Server\Http\Requests\AlertRuleRequest;
use Phattarachai\WatchtowerLaravel\Server\Mail\IssueAlertMail;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Ui\AlertRulePresenter;

final class AlertRuleController extends Controller
{
    public function store(AlertRuleRequest $request): JsonResponse
    {
        $rule = AlertRule::create([
            ...$request->attributesForRule(),
            'project_id' => (int) $request->validated('project_id'),
        ]);

        return response()->json(['rule' => AlertRulePresenter::rule($rule->load('project'))], 201);
    }

    public function update(AlertRuleRequest $request, AlertRule $rule): JsonResponse
    {
        $projectId = $request->validated('project_id');

        abort_if($projectId !== null && (int) $projectId !== (int) $rule->project_id, 404);

        $rule->update($request->attributesForRule());

        return response()->json(['rule' => AlertRulePresenter::rule($rule->refresh()->load('project'))]);
    }

    public function destroy(AlertRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * A one-off send of the rule's own template, using the project's most
     * recent event so the mail carries a real stacktrace.
     */
    public function test(AlertRule $rule): JsonResponse
    {
        $event = Event::query()
            ->where('project_id', $rule->project_id)
            ->orderByDesc('received_at')
            ->first();

        $group = $event === null ? null : IssueGroup::query()->find($event->group_id);
        $project = $rule->project;
        $emails = AlertRulePresenter::emails($rule);

        if ($event === null || $group === null || $project === null || $emails === []) {
            return response()->json([
                'message' => 'This project has no events yet, or the rule has no recipients — nothing to send a test from.',
            ], 422);
        }

        Mail::to($emails)->queue(new IssueAlertMail(
            rule: $rule->toSpec(),
            issue: AlertSnapshotFactory::issue($group),
            event: AlertSnapshotFactory::event($event),
            project: $project,
            isTest: true,
        ));

        return response()->json(['queued' => true, 'recipients' => count($emails)]);
    }
}
