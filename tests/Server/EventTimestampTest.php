<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

/**
 * `received_at` is a naive `timestamp` column, so a stored event has to land on the
 * host app's own wall clock — otherwise the UI's "Xh ago" is skewed by the app
 * timezone's offset. The stored value is read raw (not through the datetime cast) so
 * the wall clock itself is asserted.
 */
function storedReceivedAt(int $projectId, mixed $timestamp): string
{
    $payload = SentryEnvelope::eventPayload(['timestamp' => $timestamp]);

    (new ProcessEventJob($projectId, $payload))->handle();

    $event = Event::query()->where('project_id', $projectId)->latest('received_at')->firstOrFail();

    return (string) DB::table('watchtower_events')->where('id', $event->getKey())->value('received_at');
}

it('stores an epoch timestamp on the host app wall clock, not UTC', function (): void {
    config()->set('app.timezone', 'Asia/Bangkok');
    $project = makeWatchtowerProject();

    // 2026-07-01T00:00:00Z is 07:00 in Bangkok. A UTC-pinned conversion would store 00:00.
    expect(storedReceivedAt($project->id, 1782864000))->toStartWith('2026-07-01 07:00:00');
});

it('converts an offset-carrying ISO-8601 timestamp into the host app wall clock', function (): void {
    config()->set('app.timezone', 'Asia/Bangkok');
    $project = makeWatchtowerProject();

    // +09:00 Tokyo noon is 10:00 in Bangkok.
    expect(storedReceivedAt($project->id, '2026-07-01T12:00:00+09:00'))->toStartWith('2026-07-01 10:00:00');
});
