<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Phattarachai\WatchtowerCore\Sentry\AuthHeader;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates embedded ingest requests against `watchtower_projects.public_key`,
 * reading the key from Sentry's `X-Sentry-Auth` header or the `sentry_key` query
 * string that browser SDKs use.
 */
class EnvelopeKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $this->resolveProject($request);

        if ($project === null) {
            return response()->json(['error' => 'project_not_found'], 404);
        }

        if (! $project->is_active) {
            return response()->json(['error' => 'project_inactive'], 403);
        }

        $auth = $this->resolveAuth($request);

        if ($auth === null || $auth->key === null || $auth->key === '') {
            return response()->json(['error' => 'missing_sentry_auth'], 401);
        }

        if (! hash_equals($project->public_key, $auth->key)) {
            return response()->json(['error' => 'invalid_key'], 401);
        }

        $request->attributes->set('watchtower_project', $project);
        $request->attributes->set('watchtower_sentry_auth', $auth);

        return $next($request);
    }

    private function resolveProject(Request $request): ?Project
    {
        $id = (string) $request->route('project');

        if (! ctype_digit($id)) {
            return null;
        }

        return Project::query()->whereKey((int) $id)->first();
    }

    private function resolveAuth(Request $request): ?AuthHeader
    {
        $header = $request->header('X-Sentry-Auth')
            ?? $request->query('sentry_auth')
            ?? $request->query('sentry_key');

        if (! is_string($header) || $header === '') {
            return null;
        }

        if (! str_starts_with($header, 'Sentry')) {
            $header = 'Sentry sentry_version=7, sentry_key='.$header;
        }

        return AuthHeader::fromHeader($header);
    }
}
