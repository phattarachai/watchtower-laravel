<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns\ScopesToProject;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;

#[Name('snooze_issue')]
#[Description('Snooze an issue for a fixed window, or until a new event arrives. Use when the issue is real but not urgent and you want it out of the inbox without resolving it.')]
class SnoozeIssue extends Tool
{
    use ScopesToProject;

    /** @var array<string, int> Snooze windows in minutes; `until_event` has none. */
    private const array DURATIONS = [
        '1h' => 60,
        '24h' => 1440,
        '7d' => 10080,
        '30d' => 43200,
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_id' => $schema->integer()
                ->required()
                ->description('Numeric issue group id (from list_issues).'),
            'duration' => $schema->string()
                ->required()
                ->enum([...array_keys(self::DURATIONS), 'until_event'])
                ->description('Snooze window. "until_event" snoozes indefinitely; Watchtower un-snoozes on the next event.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate([
            'issue_id' => 'required|integer|min:1',
            'duration' => 'required|in:'.implode(',', [...array_keys(self::DURATIONS), 'until_event']),
        ]);

        $issue = $this->findIssueInProject((int) $args['issue_id']);

        if ($issue === null) {
            return Response::error('Issue not found in this project.');
        }

        $minutes = self::DURATIONS[$args['duration']] ?? null;
        $until = $minutes === null ? null : Carbon::now()->addMinutes($minutes);

        return Response::structured([
            'issue' => IssueListPresenter::row($this->changeStatus($issue, IssueGroup::STATUS_SNOOZED, $until)),
        ]);
    }
}
