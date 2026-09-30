<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ingest;

use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Rate limits bound how fast events enter the queue; this bounds how many can
 * sit in it. With no worker consuming — Horizon down, a supervisor missing —
 * the queue would otherwise grow by up to `rate_limit_per_min` payloads a
 * minute until Redis runs out of memory. Past `max_queue_depth` new events are
 * counted on their group instead of queued.
 *
 * The depth is read at most once per `CHECK_EVERY_SECONDS` per process: `LLEN`
 * is cheap on Redis, but the database driver answers with a COUNT(*).
 */
final class QueueBackpressure
{
    private const int CHECK_EVERY_SECONDS = 5;

    private ?bool $saturated = null;

    private int $checkedAt = 0;

    public function saturated(): bool
    {
        $limit = (int) config('watchtower.server.max_queue_depth', 5_000);

        if ($limit <= 0 || $this->connection() === 'sync') {
            return false;
        }

        if ($this->saturated === null || now()->getTimestamp() - $this->checkedAt >= self::CHECK_EVERY_SECONDS) {
            $this->saturated = $this->depth() >= $limit;
            $this->checkedAt = now()->getTimestamp();
        }

        return $this->saturated;
    }

    private function depth(): int
    {
        try {
            return Queue::connection($this->connection())->size(config('watchtower.server.queue.name'));
        } catch (Throwable) {
            return 0;
        }
    }

    private function connection(): string
    {
        return (string) (config('watchtower.server.queue.connection') ?? config('queue.default', 'sync'));
    }
}
