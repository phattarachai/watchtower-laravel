<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Jobs\ForwardEnvelope;
use Phattarachai\WatchtowerLaravel\Sentry\SelfCaptureGuard;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Mail\IssueAlertMail;
use Phattarachai\WatchtowerLaravel\Server\Models\Event as StoredEvent;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

function queuedJob(string $class, string $connection = 'redis'): Job
{
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => $class, 'command' => ''],
    ], JSON_THROW_ON_ERROR);

    return new SyncJob(app(), $payload, $connection, 'default');
}

function guard(): SelfCaptureGuard
{
    return app(SelfCaptureGuard::class);
}

it('recognises Watchtower jobs by their command name', function (): void {
    expect(guard()->isWatchtowerJob(queuedJob(ProcessEventJob::class)))->toBeTrue()
        ->and(guard()->isWatchtowerJob(queuedJob(ForwardEnvelope::class)))->toBeTrue()
        ->and(guard()->isWatchtowerJob(queuedJob('App\\Jobs\\SendInvoice')))->toBeFalse();
});

it('drops everything a worker reports while it runs a Watchtower job, until it loops', function (): void {
    event(new JobProcessing('redis', queuedJob(ProcessEventJob::class)));

    expect(guard()->inWatchtowerJob())->toBeTrue()
        ->and(guard()->shouldDrop(new RuntimeException('failed_jobs_uuid_unique')))->toBeTrue();

    event(new Looping('redis', 'default', new WorkerOptions));

    expect(guard()->inWatchtowerJob())->toBeFalse()
        ->and(guard()->shouldDrop(new RuntimeException('an app error')))->toBeFalse();
});

it('clears the worker scope when a Watchtower job succeeds or another job starts', function (): void {
    event(new JobProcessing('redis', queuedJob(ProcessEventJob::class)));
    event(new JobProcessed('redis', queuedJob(ProcessEventJob::class)));

    expect(guard()->inWatchtowerJob())->toBeFalse();

    event(new JobProcessing('redis', queuedJob(ForwardEnvelope::class)));
    event(new JobProcessing('redis', queuedJob('App\\Jobs\\SendInvoice')));

    expect(guard()->inWatchtowerJob())->toBeFalse();
});

it('never raises the worker scope for a sync job, which runs inside a request', function (): void {
    event(new JobProcessing('sync', queuedJob(ProcessEventJob::class, 'sync')));

    expect(guard()->inWatchtowerJob())->toBeFalse();
});

it('drops the exact exceptions a Watchtower job failed with, and only those', function (): void {
    $failure = new RuntimeException('deadlock');
    $failed = new RuntimeException('gave up');
    $unrelated = new RuntimeException('deadlock');

    event(new JobExceptionOccurred('sync', queuedJob(ProcessEventJob::class, 'sync'), $failure));
    event(new JobFailed('sync', queuedJob(ProcessEventJob::class, 'sync'), $failed));
    event(new JobFailed('sync', queuedJob('App\\Jobs\\SendInvoice', 'sync'), $unrelated));

    expect(guard()->shouldDrop($failure))->toBeTrue()
        ->and(guard()->shouldDrop($failed))->toBeTrue()
        ->and(guard()->shouldDrop(new LogicException('wrapped', 0, $failure)))->toBeTrue()
        ->and(guard()->shouldDrop($unrelated))->toBeFalse();
});

it('drops MaxAttemptsExceeded and timeouts that name a Watchtower job', function (): void {
    expect(guard()->shouldDrop(MaxAttemptsExceededException::forJob(queuedJob(ProcessEventJob::class))))->toBeTrue()
        ->and(guard()->shouldDrop(TimeoutExceededException::forJob(queuedJob(ForwardEnvelope::class))))->toBeTrue()
        ->and(guard()->shouldDrop(MaxAttemptsExceededException::forJob(queuedJob('App\\Jobs\\SendInvoice'))))->toBeFalse();
});

it('drops an exception whose stack runs through ProcessEventJob', function (): void {
    StoredEvent::creating(fn () => throw new RuntimeException('the events table is gone'));

    try {
        new ProcessEventJob(makeWatchtowerProject()->id, SentryEnvelope::eventPayload())->handle();
    } catch (RuntimeException $e) {
        expect(guard()->shouldDrop($e))->toBeTrue();

        return;
    }

    $this->fail('ProcessEventJob should have thrown.');
});

it('treats a queued alert mail as a Watchtower job', function (): void {
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => IssueAlertMail::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable', 'command' => ''],
    ], JSON_THROW_ON_ERROR);

    expect(guard()->isWatchtowerJob(new SyncJob(app(), $payload, 'redis', 'watchtower')))->toBeTrue()
        ->and(guard()->isWatchtowerJob(queuedJob('Illuminate\\Mail\\SendQueuedMailable')))->toBeFalse();
});
