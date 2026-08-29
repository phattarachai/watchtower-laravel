<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Phattarachai\WatchtowerLaravel\Server\Http\Requests\IssueStatusRequest;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;

final class IssueStatusController extends Controller
{
    public function __invoke(IssueStatusRequest $request, IssueGroup $group): JsonResponse
    {
        $projectId = $request->validated('project_id');

        abort_if($projectId !== null && (int) $projectId !== (int) $group->project_id, 404);

        $status = (string) $request->validated('status');

        $group->forceFill([
            'status' => $status,
            'snoozed_until' => $this->snoozedUntil($status, $request->validated('snooze_minutes')),
            'resolved_in_release' => $status === IssueGroup::STATUS_RESOLVED ? $group->resolved_in_release : null,
            'last_status_change_at' => now(),
        ])->save();

        return response()->json(['issue' => IssueListPresenter::row($group->refresh()->load('project'))]);
    }

    private function snoozedUntil(string $status, mixed $minutes): ?string
    {
        if ($status !== IssueGroup::STATUS_SNOOZED) {
            return null;
        }

        return now()->addMinutes(is_numeric($minutes) ? (int) $minutes : 60)->toDateTimeString();
    }
}
