<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the embedded MCP server against `watchtower_projects.public_key`
 * and pins the resolved project onto the request, which every tool scopes to.
 */
class McpTokenAuth
{
    public const string ATTRIBUTE = 'watchtower_project';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveKey($request);

        if ($key === null) {
            return $this->unauthorized('missing_api_key');
        }

        $project = $this->resolveProject($key);

        if ($project === null) {
            return $this->unauthorized('invalid_api_key');
        }

        $request->attributes->set(self::ATTRIBUTE, $project);

        return $next($request);
    }

    private function resolveKey(Request $request): ?string
    {
        $header = (string) ($request->header('Authorization') ?? '');

        if (str_starts_with($header, 'Bearer ')) {
            $header = trim(substr($header, 7));
        }

        $key = $header !== '' ? $header : (string) ($request->query('api_key') ?? '');

        return $key === '' ? null : $key;
    }

    /**
     * Compared with `hash_equals` over the candidate rows rather than a `where`
     * on the key, so the lookup itself is not a timing oracle.
     */
    private function resolveProject(string $key): ?Project
    {
        foreach (Project::query()->where('is_active', true)->cursor() as $project) {
            if (hash_equals($project->public_key, $key)) {
                return $project;
            }
        }

        return null;
    }

    private function unauthorized(string $error): Response
    {
        return response()->json(['error' => $error], 401);
    }
}
