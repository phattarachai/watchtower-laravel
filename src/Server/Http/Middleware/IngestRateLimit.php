<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-project ingest throttle backed by the host app's cache store, so an
 * embedded install needs no Redis.
 */
class IngestRateLimit
{
    private const int WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->attributes->get('watchtower_project');
        $limit = (int) config('watchtower.server.rate_limit_per_min', 300);

        if (! $project instanceof Project || $limit <= 0) {
            return $next($request);
        }

        $key = 'watchtower:ingest:'.$project->getKey();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->throttled(max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $response = $next($request);
        $response->headers->set('X-Sentry-Rate-Limits-Remaining', (string) RateLimiter::remaining($key, $limit));

        return $response;
    }

    private function throttled(int $retryAfter): Response
    {
        return response()
            ->json(['error' => 'rate_limited'], 429)
            ->header('Retry-After', (string) $retryAfter)
            ->header('X-Sentry-Rate-Limits', $retryAfter.':event:project');
    }
}
