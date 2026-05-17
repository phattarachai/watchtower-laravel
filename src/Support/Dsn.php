<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * @phpstan-type ParsedDsn array{
 *     scheme: string,
 *     host_with_port: string,
 *     host: string,
 *     port: int|null,
 *     public_key: string,
 *     project_id: int,
 *     relay_url: string
 * }
 */
final class Dsn
{
    /**
     * @return ParsedDsn|null
     */
    public static function parse(?string $dsn, string $relayPath = '/api/watchtower-relay'): ?array
    {
        if ($dsn === null || $dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);

        if ($parts === false || ! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host   = $parts['host'] ?? null;
        $user   = $parts['user'] ?? null;
        $path   = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';

        if ($scheme === null || $host === null || $user === null || $path === '') {
            return null;
        }

        if (! ctype_digit($path)) {
            return null;
        }

        $port           = isset($parts['port']) ? (int) $parts['port'] : null;
        $hostWithPort   = $port !== null ? $host.':'.$port : $host;

        return [
            'scheme'         => $scheme,
            'host_with_port' => $hostWithPort,
            'host'           => $host,
            'port'           => $port,
            'public_key'     => $user,
            'project_id'     => (int) $path,
            'relay_url'      => $scheme.'://'.$hostWithPort.$relayPath,
        ];
    }
}
