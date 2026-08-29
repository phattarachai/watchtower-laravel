<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

#[Name('ignore_issue')]
#[Description('Mark an issue as ignored. Use for known false-positives or noise that is not worth fixing.')]
class IgnoreIssue extends IssueStatusTool
{
    protected function targetStatus(): string
    {
        return IssueGroup::STATUS_IGNORED;
    }
}
