<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Phattarachai\WatchtowerLaravel\Server\Ingest\IngestThrottle;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 429 before the body is parsed once the project's per-minute budget is
 * spent, so the SDK backs off. The budget itself is spent per event by
 * IngestThrottle, which every ingest path shares — including the ones that
 * never pass through this middleware.
 */
class IngestRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->attributes->get('watchtower_project');
        $limit = (int) config('watchtower.server.rate_limit_per_min', 300);

        if (! $project instanceof Project || $limit <= 0) {
            return $next($request);
        }

        $key = IngestThrottle::projectKey((int) $project->getKey());

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->throttled(max(1, RateLimiter::availableIn($key)));
        }

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
