<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns\ScopesToProject;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;
use Phattarachai\WatchtowerLaravel\Support\SinceFilter;

#[Name('list_issues')]
#[Description('List issue groups (deduplicated exceptions) for this project. Use to triage what is open, filter by status / environment / level, or search by title.')]
class ListIssues extends Tool
{
    use ScopesToProject;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(IssueListPresenter::statuses())
                ->description('Filter by issue status.'),
            'environment' => $schema->string()
                ->description('Filter to issues with at least one event in this environment (e.g. "production").'),
            'level' => $schema->string()
                ->enum(['debug', 'info', 'warning', 'error', 'fatal'])
                ->description('Filter by severity.'),
            'q' => $schema->string()
                ->description('Case-insensitive search on issue title.'),
            'since' => $schema->string()
                ->description('ISO timestamp or shorthand like "15m", "6h", "7d" — only issues seen since this time.'),
            'page' => $schema->integer()
                ->description('Page number (25 per page).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate([
            'status' => 'sometimes|in:unresolved,resolved,ignored,snoozed',
            'environment' => 'sometimes|string|max:64',
            'level' => 'sometimes|in:debug,info,warning,error,fatal',
            'q' => 'sometimes|string|max:200',
            'since' => 'sometimes|string|max:32',
            'page' => 'sometimes|integer|min:1',
        ]);

        $since = SinceFilter::parse($args['since'] ?? null);

        $paginator = IssueGroup::query()
            ->where('project_id', $this->projectId())
            ->with('project')
            ->orderByDesc('last_seen_at')
            ->when($args['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($args['level'] ?? null, fn (Builder $query, string $level) => $query->where('level', $level))
            ->when($args['q'] ?? null, fn (Builder $query, string $term) => $query->where('title', 'like', '%'.$term.'%'))
            ->when($since, fn (Builder $query, $timestamp) => $query->where('last_seen_at', '>=', $timestamp))
            ->when($args['environment'] ?? null, fn (Builder $query, string $environment) => $query->whereHas(
                'events',
                fn (Builder $events) => $events->where('environment', $environment),
            ))
            ->paginate(perPage: 25, page: $args['page'] ?? 1);

        return Response::structured([
            'issues' => $paginator->getCollection()->map(IssueListPresenter::row(...))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
