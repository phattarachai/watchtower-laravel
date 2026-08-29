<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ui;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

final class IssueListPresenter
{
    public const int PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public static function props(Request $request): array
    {
        $filters = self::filters($request);
        $paginator = self::query($filters)->paginate(self::PER_PAGE)->withQueryString();

        return [
            'filters' => $filters,
            'projects' => ProjectPresenter::options(),
            'environments' => self::environments($filters['project_id']),
            'counts' => self::counts($filters),
            'issues' => [
                'data' => array_map(self::row(...), $paginator->items()),
                'meta' => self::meta($paginator),
            ],
        ];
    }

    /**
     * @return array{project_id: int|null, status: string|null, level: string|null, environment: string|null, q: string|null}
     */
    private static function filters(Request $request): array
    {
        $status = self::text($request, 'status');

        return [
            'project_id' => self::integer($request, 'project_id'),
            'status' => in_array($status, self::statuses(), true) ? $status : null,
            'level' => self::text($request, 'level'),
            'environment' => self::text($request, 'environment'),
            'q' => self::text($request, 'q'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<IssueGroup>
     */
    private static function query(array $filters): Builder
    {
        return IssueGroup::query()
            ->with('project')
            ->when($filters['project_id'] !== null, fn (Builder $query) => $query->where('project_id', $filters['project_id']))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['level'] !== null, fn (Builder $query) => $query->where('level', $filters['level']))
            ->when($filters['q'] !== null, fn (Builder $query) => $query->where('title', 'like', '%'.$filters['q'].'%'))
            ->when($filters['environment'] !== null, fn (Builder $query) => $query->whereHas(
                'events',
                fn (Builder $events) => $events->where('environment', $filters['environment']),
            ))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');
    }

    /**
     * Status tallies ignore the status filter itself — the tabs must keep
     * showing what switching to them would return.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private static function counts(array $filters): array
    {
        $base = self::query([...$filters, 'status' => null]);

        $counts = ['all' => (clone $base)->count()];

        foreach (self::statuses() as $status) {
            $counts[$status] = (clone $base)->where('status', $status)->count();
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private static function environments(?int $projectId): array
    {
        return Event::query()
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->whereNotNull('environment')
            ->distinct()
            ->orderBy('environment')
            ->limit(50)
            ->pluck('environment')
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(IssueGroup $group): array
    {
        return [
            'id' => (int) $group->getKey(),
            'title' => (string) $group->title,
            'level' => (string) $group->level,
            'status' => (string) $group->status,
            'platform' => $group->platform,
            'event_count' => (int) $group->event_count,
            'user_count' => (int) $group->user_count,
            'first_seen_at' => $group->first_seen_at?->toIso8601String(),
            'last_seen_at' => $group->last_seen_at?->toIso8601String(),
            'snoozed_until' => $group->snoozed_until?->toIso8601String(),
            'project' => $group->project === null ? null : [
                'id' => (int) $group->project->getKey(),
                'name' => (string) $group->project->name,
            ],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, IssueGroup>  $paginator
     * @return array<string, int|null>
     */
    private static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            IssueGroup::STATUS_UNRESOLVED,
            IssueGroup::STATUS_RESOLVED,
            IssueGroup::STATUS_IGNORED,
            IssueGroup::STATUS_SNOOZED,
        ];
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function integer(Request $request, string $key): ?int
    {
        $value = self::text($request, $key);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
