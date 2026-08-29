<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns\ScopesToProject;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;
use Phattarachai\WatchtowerLaravel\Support\SinceFilter;

#[Name('get_stats')]
#[Description('Snapshot of project health: event volume in a window (24h/7d/30d), top issues by recent event count, severity breakdown, and the unresolved/resolved/ignored/snoozed mix. Use this first when triaging an unfamiliar project.')]
class GetStats extends Tool
{
    use ScopesToProject;

    private const int TOP_ISSUES = 5;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->enum(['24h', '7d', '30d'])
                ->description('Time window for event counts and the top-issues ranking. Default 7d.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate(['window' => 'sometimes|in:24h,7d,30d']);

        $project = $this->project();
        $window = $args['window'] ?? '7d';
        $start = SinceFilter::parse($window);

        return Response::structured([
            'window' => $window,
            'project' => ['id' => (int) $project->getKey(), 'slug' => $project->slug, 'name' => $project->name],
            'totals' => [
                'events' => $this->eventsInWindow($start)->count(),
                'events_by_level' => $this->eventsByLevel($start),
            ],
            'top_issues' => $this->topIssues($start),
            'status_mix' => $this->statusMix(),
        ]);
    }

    /**
     * @return Builder<Event>
     */
    private function eventsInWindow(?Carbon $start): Builder
    {
        return Event::query()
            ->where('project_id', $this->projectId())
            ->when($start, fn (Builder $query, Carbon $from) => $query->where('received_at', '>=', $from));
    }

    /**
     * @return array<string, int>
     */
    private function eventsByLevel(?Carbon $start): array
    {
        return $this->eventsInWindow($start)
            ->selectRaw('level, count(*) as aggregate')
            ->groupBy('level')
            ->pluck('aggregate', 'level')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topIssues(?Carbon $start): array
    {
        $ranked = $this->eventsInWindow($start)
            ->selectRaw('group_id, count(*) as aggregate')
            ->groupBy('group_id')
            ->orderByDesc('aggregate')
            ->limit(self::TOP_ISSUES)
            ->pluck('aggregate', 'group_id');

        if ($ranked->isEmpty()) {
            return [];
        }

        $groups = IssueGroup::query()
            ->whereIn('id', $ranked->keys()->all())
            ->with('project')
            ->get()
            ->keyBy('id');

        return $ranked
            ->filter(fn (mixed $count, mixed $groupId): bool => $groups->has($groupId))
            ->map(fn (mixed $count, mixed $groupId): array => [
                ...IssueListPresenter::row($groups[$groupId]),
                'count_in_window' => (int) $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function statusMix(): array
    {
        $counts = IssueGroup::query()
            ->where('project_id', $this->projectId())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $mix = [];

        foreach (IssueListPresenter::statuses() as $status) {
            $mix[$status] = (int) ($counts[$status] ?? 0);
        }

        return $mix;
    }
}
