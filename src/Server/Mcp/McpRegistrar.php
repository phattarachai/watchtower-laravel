<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp;

use Laravel\Mcp\Facades\Mcp;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\McpTokenAuth;

/**
 * laravel/mcp is an optional dependency, so every reference to it lives behind
 * this class and is only reached once `available()` has said yes.
 */
final class McpRegistrar
{
    public const string MCP_SERVER_CLASS = 'Laravel\Mcp\Server';

    public static function available(): bool
    {
        return class_exists(self::MCP_SERVER_CLASS);
    }

    public static function enabled(): bool
    {
        return self::available() && (bool) config('watchtower.server.mcp.enabled', true);
    }

    public static function route(): string
    {
        $prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');

        return $prefix === '' ? '/mcp' : '/'.$prefix.'/mcp';
    }

    public static function register(): void
    {
        Mcp::web(self::route(), WatchtowerEmbeddedServer::class)
            ->middleware([...(array) config('watchtower.server.mcp.middleware', []), McpTokenAuth::class])
            ->name('watchtower.server.mcp');
    }
}
