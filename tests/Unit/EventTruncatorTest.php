<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Server\Ingest\EventTruncator;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

function truncator(int $maxEventBytes = 200_000, int $maxStringBytes = 8_192): EventTruncator
{
    return new EventTruncator($maxEventBytes, $maxStringBytes);
}

/**
 * The shape of the incident: a failed_jobs unique violation whose message
 * quotes the whole previous job payload.
 *
 * @return array<string, mixed>
 */
function sqlErrorEvent(int $payloadBytes): array
{
    $sql = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key failed_jobs_uuid_unique '
        .'(SQL: insert into `failed_jobs` (`payload`) values ('.str_repeat('{"rawEvent":"…"}', intdiv($payloadBytes, 16)).'))';

    return SentryEnvelope::eventPayload(['exception' => ['values' => [['type' => 'Illuminate\\Database\\UniqueConstraintViolationException', 'value' => $sql]]]]);
}

it('leaves an event under budget untouched', function (): void {
    $event = SentryEnvelope::eventPayload();

    expect(truncator()->truncate($event))->toBe($event);
});

it('caps a SQL error that quotes a 2.9 MB job payload', function (): void {
    $trimmed = truncator()->truncate(sqlErrorEvent(2_900_000));
    $value = $trimmed['exception']['values'][0]['value'];

    expect(EventTruncator::size($trimmed))->toBeLessThanOrEqual(200_000)
        ->and(strlen($value))->toBeLessThanOrEqual(8_192)
        ->and($value)->toStartWith('SQLSTATE[23000]')
        ->and($value)->toContain('…[truncated ')
        ->and($trimmed['tags'][EventTruncator::TAG])->toBe('true');
});

it('keeps only the most recent 100 breadcrumbs', function (): void {
    $crumbs = array_map(fn (int $i): array => ['message' => "crumb {$i}"], range(1, 250));
    $trimmed = truncator()->truncate(SentryEnvelope::eventPayload(['breadcrumbs' => ['values' => $crumbs]]));

    expect($trimmed['breadcrumbs']['values'])->toHaveCount(100)
        ->and($trimmed['breadcrumbs']['values'][99]['message'])->toBe('crumb 250');
});

it('drops bulky context before it touches the exception', function (): void {
    $event = SentryEnvelope::eventPayload([
        'extra' => ['dump' => array_fill(0, 400, str_repeat('x', 1_000))],
        'request' => ['data' => ['blob' => array_fill(0, 100, str_repeat('y', 1_000))]],
    ]);

    $trimmed = truncator(maxEventBytes: 50_000)->truncate($event);

    expect(EventTruncator::size($trimmed))->toBeLessThanOrEqual(50_000)
        ->and($trimmed)->not->toHaveKey('extra')
        ->and($trimmed['request'])->not->toHaveKey('data')
        ->and($trimmed['request']['url'])->toBe('https://apps.test/checkout')
        ->and($trimmed['exception']['values'][0]['stacktrace']['frames'])->toHaveCount(2);
});

it('cuts the middle out of a very deep stack', function (): void {
    $frames = array_map(fn (int $i): array => [
        'filename' => "app/Frame{$i}.php", 'function' => "f{$i}", 'lineno' => $i, 'in_app' => false,
        'vars' => ['blob' => str_repeat('v', 2_000)],
    ], range(1, 500));
    $event = SentryEnvelope::eventPayload(['exception' => ['values' => [['type' => 'Error', 'value' => 'deep', 'stacktrace' => ['frames' => $frames]]]]]);

    $kept = truncator(maxEventBytes: 60_000)->truncate($event)['exception']['values'][0]['stacktrace']['frames'];

    expect($kept)->toHaveCount(50)
        ->and($kept[0]['function'])->toBe('f1')
        ->and($kept[49]['function'])->toBe('f500')
        ->and($kept[49])->not->toHaveKey('vars');
});

it('always lands under budget, down to the exception skeleton', function (): void {
    $event = SentryEnvelope::eventPayload(['tags' => array_fill_keys(array_map(fn (int $i): string => "tag{$i}", range(1, 5_000)), 'value')]);

    $trimmed = truncator(maxEventBytes: 4_000)->truncate($event);

    expect(EventTruncator::size($trimmed))->toBeLessThanOrEqual(4_000)
        ->and($trimmed['exception']['values'][0]['type'])->toBe('RuntimeException')
        ->and($trimmed['event_id'])->toBe($event['event_id']);
});

it('is idempotent, so the job can re-run it on a trimmed event', function (): void {
    $once = truncator()->truncate(sqlErrorEvent(500_000));

    expect(truncator()->truncate($once))->toBe($once);
});

it('never splits a multi-byte character', function (): void {
    $event = SentryEnvelope::eventPayload(['message' => str_repeat('ข้อผิดพลาด', 5_000)]);

    $trimmed = truncator()->truncate($event);

    expect(mb_check_encoding($trimmed['message'], 'UTF-8'))->toBeTrue()
        ->and(json_encode($trimmed))->not->toBeFalse();
});

it('is disabled by a zero budget', function (): void {
    $event = sqlErrorEvent(300_000);

    expect(truncator(maxEventBytes: 0)->truncate($event))->toBe($event);
});
