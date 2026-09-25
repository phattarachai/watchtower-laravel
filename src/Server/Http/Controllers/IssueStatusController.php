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

        $group->changeStatus((string) $request->validated('status'), $request->snoozeMinutes());

        return response()->json(['issue' => IssueListPresenter::row($group->refresh()->load('project'))]);
    }
}
