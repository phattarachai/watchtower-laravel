<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

#[Name('unresolve_issue')]
#[Description('Re-open an issue that was previously resolved or ignored. Use when the fix did not stick, or the issue was closed prematurely.')]
class UnresolveIssue extends IssueStatusTool
{
    protected function targetStatus(): string
    {
        return IssueGroup::STATUS_UNRESOLVED;
    }
}
