<?php

declare(strict_types=1);

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Event as StoredEvent;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Phattarachai\WatchtowerLaravel\Tests\Fixtures\Jobs\ExplodingJob;
use Phattarachai\WatchtowerLaravel\Tests\SelfCaptureTestCase;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;

function selfCaptureProject(): Project
{
    return makeWatchtowerProject(['id' => 1, 'public_key' => SelfCaptureTestCase::PUBLIC_KEY]);
}

function runQueueWorkerOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

/** Pretend earlier workers already burned every attempt, as a hung worker would. */
function exhaustQueuedAttempts(int $attempts = 3): void
{
    DB::table('jobs')->update(['attempts' => $attempts]);
}

function queuedWatchtowerJobs(): int
{
    return DB::table('jobs')->where('payload', 'like', '%ProcessEventJob%')->count();
}

it('runs with the in-process transport bound', function (): void {
    expect(SentrySdk::getCurrentHub()->getClient()?->getTransport())->toBeInstanceOf(LocalTransport::class);
});

it('still captures a failing host job onto the Watchtower queue', function (): void {
    selfCaptureProject();
    ExplodingJob::dispatch();

    runQueueWorkerOnce();

    expect(queuedWatchtowerJobs())->toBe(1);

    runQueueWorkerOnce();

    expect(StoredEvent::query()->count())->toBe(1)
        ->and(data_get(StoredEvent::query()->firstOrFail()->payload, 'exception.values.0.value'))->toBe('the host job exploded');
});

it('does not capture a ProcessEventJob that exceeded its attempts', function (): void {
    $project = selfCaptureProject();
    ProcessEventJob::dispatch($project->id, SentryEnvelope::eventPayload());
    exhaustQueuedAttempts();

    runQueueWorkerOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(StoredEvent::query()->count())->toBe(0);
});

it('does not capture the failed_jobs unique violation of a job restored from a snapshot', function (): void {
    $project = selfCaptureProject();
    ProcessEventJob::dispatch($project->id, SentryEnvelope::eventPayload());

    $payload = (string) DB::table('jobs')->value('payload');
    DB::table('failed_jobs')->insert([
        'uuid' => json_decode($payload, true)['uuid'],
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => 'the first failure, before Redis reloaded its RDB',
    ]);
    exhaustQueuedAttempts();

    runQueueWorkerOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('does not capture an exception thrown inside ProcessEventJob', function (): void {
    $project = selfCaptureProject();
    StoredEvent::creating(fn () => throw new RuntimeException('the events table is gone'));
    ProcessEventJob::dispatch($project->id, SentryEnvelope::eventPayload());
    exhaustQueuedAttempts(2);

    runQueueWorkerOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and((string) DB::table('failed_jobs')->value('exception'))->toContain('the events table is gone');
});

it('keeps capturing host exceptions once the worker moves past a Watchtower job', function (): void {
    $project = selfCaptureProject();
    ProcessEventJob::dispatch($project->id, SentryEnvelope::eventPayload());
    exhaustQueuedAttempts();
    runQueueWorkerOnce();

    ExplodingJob::dispatch();
    runQueueWorkerOnce();

    expect(queuedWatchtowerJobs())->toBe(1);
});

it('drops a Watchtower job failure in the chained before_send, ahead of the host callback', function (): void {
    $payload = json_encode(['uuid' => 'x', 'displayName' => ProcessEventJob::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => ProcessEventJob::class]]);
    $failure = MaxAttemptsExceededException::forJob(new SyncJob(app(), (string) $payload, 'redis', 'default'));
    $beforeSend = SentrySdk::getCurrentHub()->getClient()?->getOptions()->getBeforeSendCallback();

    expect($beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => $failure])))->toBeNull()
        ->and($beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => new RuntimeException('app')])))->not->toBeNull();
});
