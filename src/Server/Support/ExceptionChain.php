<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Support;

/**
 * Reads `exception.values` the way Sentry orders it: oldest cause first, so the
 * exception that was actually thrown — the one the issue title names — is last.
 */
final class ExceptionChain
{
    /**
     * The thrown exception first, then each cause it wraps.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public static function outermostFirst(array $payload): array
    {
        $values = data_get($payload, 'exception.values');

        if (! is_array($values)) {
            return [];
        }

        return array_reverse(array_values(array_filter($values, is_array(...))));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function thrown(array $payload): ?array
    {
        return self::outermostFirst($payload)[0] ?? null;
    }
}
