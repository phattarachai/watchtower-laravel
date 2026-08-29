<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\NotificationSent;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

/**
 * Drops aged event rows and the alert-dedup ledger. Issue groups survive: a
 * group with a zero event_count is still the record that this class of failure
 * was seen, and its status is what stops it being re-alerted.
 */
final class PruneCommand extends Command
{
    private const int CHUNK = 1000;

    private const int NOTIFICATION_KEEP_DAYS = 90;

    protected $signature = 'watchtower:prune';

    protected $description = 'Delete Watchtower events past their retention window and stale notification records.';

    public function handle(): int
    {
        $events = 0;

        foreach (Project::query()->orderBy('id')->get() as $project) {
            $events += $this->pruneProject($project);
        }

        $this->line("Events past retention: {$events} row(s) deleted.");

        $notifications = $this->pruneNotifications();
        $this->line('Notification records older than '.self::NOTIFICATION_KEEP_DAYS." days: {$notifications} row(s) deleted.");

        return self::SUCCESS;
    }

    private function pruneProject(Project $project): int
    {
        $days = $project->retention_days ?? (int) config('watchtower.server.retention_days', 90);

        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);

        return $this->deleteInChunks(fn (): Builder => Event::query()
            ->where('project_id', $project->getKey())
            ->where('received_at', '<', $cutoff));
    }

    private function pruneNotifications(): int
    {
        $cutoff = Carbon::now()->subDays(self::NOTIFICATION_KEEP_DAYS);

        return $this->deleteInChunks(fn (): Builder => NotificationSent::query()
            ->where('sent_at', '<', $cutoff));
    }

    /**
     * Deletes by explicit key batches: Postgres has no `DELETE ... LIMIT`, so a
     * limited builder would silently delete the whole match in one statement.
     *
     * @param  Closure(): Builder<*>  $filtered  a fresh, filtered query per pass
     */
    private function deleteInChunks(Closure $filtered): int
    {
        $deleted = 0;

        while (true) {
            $keys = $filtered()->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($keys->isEmpty()) {
                return $deleted;
            }

            $removed = $filtered()->whereIn('id', $keys->all())->delete();

            if ($removed === 0) {
                return $deleted;
            }

            $deleted += $removed;
        }
    }
}
