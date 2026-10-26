<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the embedded MCP server with a project's MCP token and pins
 * the resolved project onto the request, which every tool scopes to. The
 * public DSN key is accepted only while `server.mcp.legacy_keys` is on.
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

        $project = $this->resolveByMcpToken($key);

        if ($project !== null) {
            $request->attributes->set(self::ATTRIBUTE, $project);

            return $next($request);
        }

        $project = (bool) config('watchtower.server.mcp.legacy_keys', true)
            ? $this->resolveByPublicKey($key)
            : null;

        if ($project === null) {
            return $this->unauthorized('invalid_api_key');
        }

        $this->warnLegacyKey($project);
        $request->attributes->set(self::ATTRIBUTE, $project);

        $response = $next($request);
        $response->headers->set('Deprecation', 'true');

        return $response;
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

    private function resolveByMcpToken(string $key): ?Project
    {
        return Project::query()
            ->where('is_active', true)
            ->where('mcp_token_hash', Project::hashMcpToken($key))
            ->first();
    }

    /**
     * Compared with `hash_equals` over the candidate rows rather than a `where`
     * on the key, so the lookup itself is not a timing oracle.
     */
    private function resolveByPublicKey(string $key): ?Project
    {
        foreach (Project::query()->where('is_active', true)->cursor() as $project) {
            if (hash_equals($project->public_key, $key)) {
                return $project;
            }
        }

        return null;
    }

    private function warnLegacyKey(Project $project): void
    {
        if (! Cache::add('watchtower:mcp-legacy-key-warned:'.$project->getKey(), true, now()->addHour())) {
            return;
        }

        Log::warning('Watchtower MCP authenticated with a public DSN key. Mint an MCP token with `php artisan watchtower:project mcp-token '.$project->slug.'`, send it from .mcp.json, then set WATCHTOWER_MCP_LEGACY_KEYS=false.');
    }

    private function unauthorized(string $error): Response
    {
        return response()->json(['error' => $error], 401);
    }
}
