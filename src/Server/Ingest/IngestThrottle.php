<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ingest;

use Illuminate\Support\Facades\RateLimiter;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Throwable;

/**
 * Per-event admission for every ingest path — the HTTP endpoint, the relay in
 * standalone/dual mode, and in-process self-capture, which never passes through
 * route middleware. Two budgets, both per minute over the host's cache store:
 *
 * - `rate_limit_per_min` for the whole project (the same key IngestRateLimit
 *   checks, so an HTTP client over it gets a 429 before its body is parsed);
 * - `rate_limit_per_fingerprint_per_min` for one issue, so a single hot error
 *   cannot spend the whole project budget or flood the queue.
 *
 * An event over either budget is not queued. If its group already exists it is
 * still counted — `event_count` and `last_seen_at` move, no payload is stored —
 * so the issue list keeps showing the real volume.
 */
final class IngestThrottle
{
    private const int WINDOW_SECONDS = 60;

    public static function projectKey(int $projectId): string
    {
        return "watchtower:ingest:{$projectId}";
    }

    public function admit(int $projectId, string $fingerprint): bool
    {
        try {
            return $this->withinBudgets($projectId, $fingerprint);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Count an event that was not admitted against its existing group.
     */
    public function countDropped(int $projectId, string $fingerprint): void
    {
        try {
            IssueGroup::query()
                ->where('project_id', $projectId)
                ->where('fingerprint', $fingerprint)
                ->increment('event_count', 1, ['last_seen_at' => now()]);
        } catch (Throwable) {
            // The request that reported the event must never fail on bookkeeping.
        }
    }

    private function withinBudgets(int $projectId, string $fingerprint): bool
    {
        $projectKey = self::projectKey($projectId);
        $fingerprintKey = "{$projectKey}:fp:{$fingerprint}";
        $projectLimit = (int) config('watchtower.server.rate_limit_per_min', 300);
        $fingerprintLimit = (int) config('watchtower.server.rate_limit_per_fingerprint_per_min', 20);

        if ($this->exhausted($fingerprintKey, $fingerprintLimit) || $this->exhausted($projectKey, $projectLimit)) {
            return false;
        }

        $this->spend($fingerprintKey, $fingerprintLimit);
        $this->spend($projectKey, $projectLimit);

        return true;
    }

    private function exhausted(string $key, int $limit): bool
    {
        return $limit > 0 && RateLimiter::tooManyAttempts($key, $limit);
    }

    private function spend(string $key, int $limit): void
    {
        if ($limit > 0) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }
    }
}
