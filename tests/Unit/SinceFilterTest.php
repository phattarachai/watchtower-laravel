<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Phattarachai\WatchtowerLaravel\Support\SinceFilter;

it('parses shorthand windows relative to now', function (string $value, int $seconds): void {
    Carbon::setTestNow('2026-01-01 12:00:00');

    expect(SinceFilter::parse($value)?->diffInSeconds(Carbon::now(), absolute: true))
        ->toEqualWithDelta($seconds, 1);

    Carbon::setTestNow();
})->with([
    ['15m', 900],
    ['6h', 21600],
    ['7d', 604800],
]);

it('parses an ISO timestamp', function (): void {
    expect(SinceFilter::parse('2026-01-01T00:00:00Z')?->toDateString())->toBe('2026-01-01');
});

it('returns null for empty or unparseable input', function (): void {
    expect(SinceFilter::parse(null))->toBeNull()
        ->and(SinceFilter::parse(''))->toBeNull()
        ->and(SinceFilter::parse('not a date at all'))->toBeNull();
});
