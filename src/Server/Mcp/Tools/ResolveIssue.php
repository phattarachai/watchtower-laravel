<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

#[Name('resolve_issue')]
#[Description('Mark an issue as resolved. Use after fixing the underlying bug — Watchtower re-opens it automatically when a new event arrives.')]
class ResolveIssue extends IssueStatusTool
{
    protected function targetStatus(): string
    {
        return IssueGroup::STATUS_RESOLVED;
    }
}
