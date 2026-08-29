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
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueDetailPresenter;

#[Name('get_event')]
#[Description('Fetch the full event payload (stacktrace frames, breadcrumbs, request, contexts, tags) for one event id. The debugging entry point: get_issue gives the summary, get_event gives the data needed to fix the bug.')]
class GetEvent extends Tool
{
    use ScopesToProject;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()
                ->required()
                ->description('Watchtower event id (UUID, from list_events or get_issue.latest_event_id).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate(['event_id' => 'required|string|max:64']);

        $event = Event::query()
            ->where('project_id', $this->projectId())
            ->whereKey($args['event_id'])
            ->first();

        if ($event === null) {
            return Response::error('Event not found in this project. Ingestion can be queued — retry after a few seconds if you just captured this event.');
        }

        return Response::structured([
            'event' => [
                ...IssueDetailPresenter::event($event),
                'group_id' => (int) $event->group_id,
            ],
        ]);
    }
}
