<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ingest;

use Phattarachai\WatchtowerCore\Ingest\EventNormalizer;
use Phattarachai\WatchtowerCore\Ingest\EventScrubber;
use Phattarachai\WatchtowerCore\Ingest\Fingerprinter;
use Phattarachai\WatchtowerCore\Ingest\MessageNormalizer;

/**
 * The scrub → normalize → truncate steps every event goes through, shared by
 * the accepter (before the event is queued) and ProcessEventJob (which re-runs
 * them — each step is idempotent, and jobs queued by an older release have not
 * been through them yet). Running them before dispatch keeps secrets and
 * unbounded payloads out of the queue backend.
 */
final class EventPipeline
{
    private readonly Fingerprinter $fingerprinter;

    public function __construct()
    {
        $this->fingerprinter = new Fingerprinter(new MessageNormalizer);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function prepare(array $raw): array
    {
        return EventTruncator::fromConfig()->truncate(
            $this->normalizer()->normalize($this->scrubber()->scrub($raw)),
        );
    }

    /**
     * @param  array<string, mixed>  $event  a prepared event
     */
    public function fingerprint(array $event): string
    {
        return $this->fingerprinter->compute($event);
    }

    /**
     * @param  array<string, mixed>  $event  a prepared event
     */
    public function title(array $event): string
    {
        return $this->fingerprinter->title($event);
    }

    private function scrubber(): EventScrubber
    {
        return new EventScrubber(
            headerKeys: (array) config('watchtower.server.ingest.scrub.header_keys', []),
            bodyKeys: (array) config('watchtower.server.ingest.scrub.body_keys', []),
            placeholder: (string) config('watchtower.server.ingest.scrub.placeholder', '[Filtered]'),
        );
    }

    private function normalizer(): EventNormalizer
    {
        return new EventNormalizer(
            allowedEventFields: (array) config('watchtower.server.ingest.allowed_event_fields', []),
            allowedContextKeys: (array) config('watchtower.server.ingest.allowed_context_keys', []),
        );
    }
}
