<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Parses the `since` argument the MCP tools accept: either an ISO timestamp or
 * the shorthand forms `15m`, `6h`, `7d`.
 */
final class SinceFilter
{
    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^(\d+)([mhd])$/', $value, $matches) === 1) {
            $amount = (int) $matches[1];

            return match ($matches[2]) {
                'm' => Carbon::now()->subMinutes($amount),
                'h' => Carbon::now()->subHours($amount),
                default => Carbon::now()->subDays($amount),
            };
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
