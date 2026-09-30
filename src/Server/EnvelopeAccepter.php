<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server;

use Illuminate\Http\Request;
use Phattarachai\WatchtowerCore\Ingest\EventPipeline;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeItem;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeParser;
use Phattarachai\WatchtowerCore\Support\DsnParser;
use Phattarachai\WatchtowerCore\Support\Gzip;
use Phattarachai\WatchtowerLaravel\Server\Ingest\IngestThrottle;
use Phattarachai\WatchtowerLaravel\Server\Ingest\QueueBackpressure;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

/**
 * Shared entry point for embedded ingest: both the dedicated `/…/envelope`
 * endpoint and the relay route in standalone/dual mode funnel through here.
 */
class EnvelopeAccepter
{
    public function __construct(
        private readonly EnvelopeParser $parser,
        private readonly IngestThrottle $throttle,
        private readonly QueueBackpressure $backpressure,
    ) {}

    /**
     * IngestSizeLimit only sees the compressed body, so the inflated size is
     * capped here too — a 1 MB gzip body can otherwise expand to hundreds of MB.
     * A body that would inflate past the cap decodes to an empty envelope.
     */
    public function body(Request $request): string
    {
        $cap = (int) config('watchtower.server.max_payload_bytes', 1_048_576) * 20;

        return Gzip::decodeBody($request->getContent(), $request->header('Content-Encoding'), $cap > 0 ? $cap : null);
    }

    /**
     * @return array{header: array<string, mixed>, items: array<int, EnvelopeItem>}
     */
    public function parse(string $body): array
    {
        return $this->parser->parse($body);
    }

    /**
     * Queue one job per admitted `event` item and echo back the envelope's event id.
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

        $pipeline = app(EventPipeline::class);
        $event = $pipeline->prepare($item->payload);
        $fingerprint = $pipeline->fingerprint($event);

        if ($this->backpressure->saturated() || ! $this->throttle->admit($projectId, $fingerprint)) {
            $this->throttle->countDropped($projectId, $fingerprint);

            return;
        }

        ProcessEventJob::dispatch($projectId, $event, $sdkName);
    }
}
