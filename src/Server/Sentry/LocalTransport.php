<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Sentry;

use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Sentry\Event;
use Sentry\Serializer\PayloadSerializerInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Throwable;

/**
 * Delivers this application's own Sentry events straight into the embedded
 * store: the SDK serializes the envelope exactly as it would for an HTTP POST,
 * and it is handed to the same accepter the ingest route uses. No socket, no
 * round trip, and BeforeSend has already run by the time send() is called.
 */
final class LocalTransport implements TransportInterface
{
    private bool $sending = false;

    public function __construct(
        private readonly PayloadSerializerInterface $serializer,
        private readonly EnvelopeAccepter $accepter,
    ) {}

    public function send(Event $event): Result
    {
        if ($this->sending) {
            return new Result(ResultStatus::skipped(), $event);
        }

        $this->sending = true;

        try {
            return $this->ingest($event);
        } catch (Throwable) {
            return new Result(ResultStatus::failed());
        } finally {
            $this->sending = false;
        }
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /**
     * A payload whose DSN does not resolve to a live project is dropped rather
     * than raised — an unreachable destination must never break the request
     * that happened to throw.
     */
    private function ingest(Event $event): Result
    {
        $envelope = $this->accepter->parse($this->serializer->serialize($event));
        $project = $this->accepter->projectFromEnvelope($envelope['header']);

        if ($project === null) {
            return new Result(ResultStatus::skipped(), $event);
        }

        $this->accepter->ingest($project, $envelope, $this->accepter->sdkNameFromEnvelope($envelope['header']));

        return new Result(ResultStatus::success(), $event);
    }
}
