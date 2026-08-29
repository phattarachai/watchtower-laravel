<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns;

use Illuminate\Support\Carbon;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\McpTokenAuth;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use RuntimeException;

trait ScopesToProject
{
    protected function project(): Project
    {
        $project = request()->attributes->get(McpTokenAuth::ATTRIBUTE);

        if (! $project instanceof Project) {
            throw new RuntimeException('The Watchtower MCP request was not authenticated against a project.');
        }

        return $project;
    }

    protected function projectId(): int
    {
        return (int) $this->project()->getKey();
    }

    protected function findIssueInProject(int $id): ?IssueGroup
    {
        return IssueGroup::query()
            ->where('project_id', $this->projectId())
            ->find($id);
    }

    /**
     * Status writes are identical across resolve / ignore / unresolve — only
     * the target status differs.
     */
    protected function changeStatus(IssueGroup $group, string $status, ?Carbon $snoozedUntil = null): IssueGroup
    {
        $group->forceFill([
            'status' => $status,
            'snoozed_until' => $snoozedUntil,
            'last_status_change_at' => Carbon::now(),
        ])->save();

        return $group->refresh()->load('project');
    }
}
