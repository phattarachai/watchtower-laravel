<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\GetEvent;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\GetIssue;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\GetStats;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\IgnoreIssue;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\ListEvents;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\ListIssues;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\ResolveIssue;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\SnoozeIssue;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\UnresolveIssue;

#[Name('Watchtower')]
#[Version('1.0.0')]
#[Instructions('Query and triage exception groups stored by this application\'s embedded Watchtower. Every tool is scoped to the project whose public key authenticated the request.')]
class WatchtowerEmbeddedServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ListIssues::class,
        GetIssue::class,
        ListEvents::class,
        GetEvent::class,
        ResolveIssue::class,
        IgnoreIssue::class,
        UnresolveIssue::class,
        SnoozeIssue::class,
        GetStats::class,
    ];
}
