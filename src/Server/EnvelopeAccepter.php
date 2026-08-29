<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server;

use Illuminate\Http\Request;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeItem;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeParser;
use Phattarachai\WatchtowerCore\Support\DsnParser;
use Phattarachai\WatchtowerCore\Support\Gzip;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

/**
 * Shared entry point for embedded ingest: both the dedicated `/…/envelope`
 * endpoint and the relay route in standalone/dual mode funnel through here.
 */
class EnvelopeAccepter
{
    public function __construct(private readonly EnvelopeParser $parser) {}

    public function body(Request $request): string
    {
        return Gzip::decodeBody($request->getContent(), $request->header('Content-Encoding'));
    }

    /**
     * @return array{header: array<string, mixed>, items: array<int, EnvelopeItem>}
     */
    public function parse(string $body): array
    {
        return $this->parser->parse($body);
    }

    /**
     * Dispatch one job per `event` item and echo back the envelope's event id.
     *
     * @param  array{header: array<string, mixed>, items: array<int, EnvelopeItem>}  $envelope
     */
    public function ingest(Project $project, array $envelope, ?string $sdkName = null): string
    {
        foreach ($envelope['items'] as $item) {
            $this->dispatchIfEvent((int) $project->getKey(), $item, $sdkName);
        }

        return (string) ($envelope['header']['event_id'] ?? '');
    }

    /**
     * Resolve the project a tunnelled envelope belongs to from its header DSN.
     *
     * @param  array<string, mixed>  $header
     */
    public function projectFromEnvelope(array $header): ?Project
    {
        $parsed = $this->parseDsn($header['dsn'] ?? null);

        if ($parsed === null) {
            return null;
        }

        $project = Project::query()->whereKey($parsed['project_id'])->first();

        if ($project === null || ! $project->is_active) {
            return null;
        }

        return hash_equals($project->public_key, $parsed['public_key']) ? $project : null;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    public function sdkNameFromEnvelope(array $header): ?string
    {
        $name = $header['sdk']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The core parser only accepts a bare numeric path. Embedded DSNs carry the
     * app's URL prefix in front of the project id, so retry with the prefix
     * stripped before giving up.
     *
     * @return array<string, mixed>|null
     */
    private function parseDsn(mixed $dsn): ?array
    {
        return DsnParser::parse($dsn) ?? DsnParser::parse($this->stripPathPrefix($dsn));
    }

    private function stripPathPrefix(mixed $dsn): ?string
    {
        if (! is_string($dsn) || $dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);

        if (! is_array($parts) || ! isset($parts['path'])) {
            return null;
        }

        $segments = explode('/', trim((string) $parts['path'], '/'));
        $projectId = array_pop($segments);

        if ($segments === []) {
            return null;
        }

        return str_replace((string) $parts['path'], '/'.$projectId, $dsn);
    }

    private function dispatchIfEvent(int $projectId, EnvelopeItem $item, ?string $sdkName): void
    {
        if ($item->type() !== 'event' || ! is_array($item->payload)) {
            return;
        }

        ProcessEventJob::dispatch($projectId, $item->payload, $sdkName);
    }
}
