<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns\ScopesToProject;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;

#[Name('get_issue')]
#[Description('Fetch one issue group by id. Use after list_issues to drill into details — returns the issue summary plus latest_event_id so get_event can be called without a list_events round-trip.')]
class GetIssue extends Tool
{
    use ScopesToProject;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_id' => $schema->integer()
                ->required()
                ->description('Numeric issue group id (from list_issues).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate(['issue_id' => 'required|integer|min:1']);

        $issue = $this->findIssueInProject((int) $args['issue_id']);

        if ($issue === null) {
            return Response::error('Issue not found in this project.');
        }

        $issue->loadMissing('project');

        $latestEventId = Event::query()
            ->where('group_id', $issue->getKey())
            ->orderByDesc('received_at')
            ->value('id');

        return Response::structured([
            'issue' => [
                ...IssueListPresenter::row($issue),
                'fingerprint' => (string) $issue->fingerprint,
                'resolved_in_release' => $issue->resolved_in_release,
                'latest_event_id' => $latestEventId,
            ],
        ]);
    }
}
