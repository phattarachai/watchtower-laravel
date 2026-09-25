<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Phattarachai\WatchtowerLaravel\Server\Http\Requests\BulkIssueRequest;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueUser;
use Phattarachai\WatchtowerLaravel\Server\Models\NotificationSent;

final class BulkIssueController extends Controller
{
    public function status(BulkIssueRequest $request): JsonResponse
    {
        $groups = $this->groups($request);
        $status = (string) $request->validated('status');

        IssueGroup::query()->getConnection()->transaction(
            fn () => $groups->each(fn (IssueGroup $group) => $group->changeStatus($status, $request->snoozeMinutes())),
        );

        return response()->json(['updated' => $groups->count()]);
    }

    public function destroy(BulkIssueRequest $request): JsonResponse
    {
        $ids = $this->groups($request)->modelKeys();

        IssueGroup::query()->getConnection()->transaction(function () use ($ids): void {
            Event::query()->whereIn('group_id', $ids)->delete();
            IssueUser::query()->whereIn('group_id', $ids)->delete();
            NotificationSent::query()->whereIn('group_id', $ids)->delete();
            IssueGroup::query()->whereKey($ids)->delete();
        });

        return response()->json(['deleted' => count($ids)]);
    }

    /**
     * @return Collection<int, IssueGroup>
     */
    private function groups(BulkIssueRequest $request): Collection
    {
        return IssueGroup::query()->whereKey($request->ids())->get();
    }
}
