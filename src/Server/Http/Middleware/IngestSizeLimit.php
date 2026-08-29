<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IngestSizeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $cap = (int) config('watchtower.server.max_payload_bytes', 1_048_576);
        $declared = (int) $request->header('Content-Length', '0');
        $actual = strlen($request->getContent());

        if (max($declared, $actual) > $cap) {
            return response()->json(['error' => 'payload_too_large', 'limit_bytes' => $cap], 413);
        }

        return $next($request);
    }
}
