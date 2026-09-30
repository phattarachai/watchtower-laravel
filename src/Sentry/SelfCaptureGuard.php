<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Sentry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\MaxAttemptsExceededException;
use Phattarachai\WatchtowerLaravel\Jobs\ForwardEnvelope;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Mail\IssueAlertMail;
use Throwable;
use WeakMap;

/**
 * Keeps Watchtower from reporting its own queue failures back into itself.
 *
 * A failing ProcessEventJob is reported by the worker, the report becomes a new
 * event, the event becomes a new ProcessEventJob — and each generation carries
 * the last one's payload inside its SQL error. LocalTransport's re-entrancy flag
 * cannot see that loop because every turn of it happens in a different job.
 *
 * Two signals, because the worker reports *after* every queue event has fired:
 *
 * - **Worker scope.** From JobProcessing of a Watchtower job until the worker
 *   loops or picks up another job, everything this process reports belongs to
 *   that job — including the `failed_jobs` insert blowing up inside JobFailed,
 *   an exception the job never saw. Sync jobs are excluded: they run inside a
 *   request, and a flag left raised there would silence the rest of it.
 * - **Exception identity.** The exact throwables handed to JobExceptionOccurred
 *   and JobFailed for a Watchtower job, plus any MaxAttemptsExceededException
 *   (or its TimeoutExceededException child) naming one, plus any throwable whose
 *   stack runs through a Watchtower job. These hold on the sync queue too.
 */
final class SelfCaptureGuard
{
    /**
     * Queued alert mail is on the list because a failing SMTP send would be
     * captured, match an alert rule, and queue another mail to the same SMTP.
     *
     * @var list<class-string>
     */
    private const array JOBS = [ProcessEventJob::class, ForwardEnvelope::class, IssueAlertMail::class];

    private bool $inWatchtowerJob = false;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $failures;

    public function __construct()
    {
        $this->failures = new WeakMap;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->inWatchtowerJob = $event->connectionName !== 'sync' && $this->isWatchtowerJob($event->job);
        });

        $events->listen(JobProcessed::class, function (): void {
            $this->inWatchtowerJob = false;
        });

        $events->listen(Looping::class, function (): void {
            $this->inWatchtowerJob = false;
        });

        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            $this->remember($event->job, $event->exception);
        });

        $events->listen(JobFailed::class, function (JobFailed $event): void {
            $this->remember($event->job, $event->exception);
        });
    }

    /**
     * True while a queue worker is running, failing or recording the failure of
     * a Watchtower job.
     */
    public function inWatchtowerJob(): bool
    {
        return $this->inWatchtowerJob;
    }

    public function shouldDrop(?Throwable $exception): bool
    {
        if ($this->inWatchtowerJob) {
            return true;
        }

        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($this->isWatchtowerFailure($e)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw-body probe runs first because this is called for every job the
     * host's workers pick up — most never need their payload decoded.
     */
    public function isWatchtowerJob(Job $job): bool
    {
        if (! str_contains($job->getRawBody(), 'WatchtowerLaravel')) {
            return false;
        }

        $payload = $job->payload();

        return array_intersect([$payload['data']['commandName'] ?? null, $payload['displayName'] ?? null], self::JOBS) !== [];
    }

    /** Test seam: forget any worker scope left over from a previous job. */
    public function reset(): void
    {
        $this->inWatchtowerJob = false;
        $this->failures = new WeakMap;
    }

    private function remember(Job $job, Throwable $exception): void
    {
        if ($this->isWatchtowerJob($job)) {
            $this->failures[$exception] = true;
        }
    }

    private function isWatchtowerFailure(Throwable $e): bool
    {
        if (isset($this->failures[$e])) {
            return true;
        }

        if ($e instanceof MaxAttemptsExceededException && $e->job instanceof Job && $this->isWatchtowerJob($e->job)) {
            return true;
        }

        foreach ($e->getTrace() as $frame) {
            if (in_array($frame['class'] ?? null, self::JOBS, true)) {
                return true;
            }
        }

        return false;
    }
}
