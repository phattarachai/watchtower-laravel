<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Phattarachai\WatchtowerLaravel\Tests\Fixtures\Jobs\ExplodingJob;
use Phattarachai\WatchtowerLaravel\Tests\SelfCaptureTestCase;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;
use Sentry\SentrySdk;

function loopbackProject(): void
{
    makeWatchtowerProject(['id' => 1, 'public_key' => SelfCaptureTestCase::PUBLIC_KEY]);
}

function workOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

function waitingWatchtowerJobs(): int
{
    return DB::table('jobs')->where('payload', 'like', '%ProcessEventJob%')->count();
}

it('reports over the SDK HTTP transport, not the in-process one', function (): void {
    expect(SentrySdk::getCurrentHub()->getClient()?->getTransport())->not->toBeInstanceOf(LocalTransport::class);
});

it('loops a host job failure back through the ingest route', function (): void {
    loopbackProject();
    ExplodingJob::dispatch();

    workOnce();

    expect(waitingWatchtowerJobs())->toBe(1);
});

it('does not loop a failing ProcessEventJob even with the BeforeSend filter disabled', function (): void {
    loopbackProject();
    ProcessEventJob::dispatch(1, SentryEnvelope::eventPayload());
    DB::table('jobs')->update(['attempts' => 3]);

    workOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});
