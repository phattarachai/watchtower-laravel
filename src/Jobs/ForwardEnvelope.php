<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Jobs;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Phattarachai\WatchtowerLaravel\Support\EnvelopeForwarder;

/**
 * Async relay forward. An unreachable upstream or a 5xx is retried with
 * backoff; a 4xx — including 429, the upstream asking us to back off — is
 * dropped, since resending the same bytes cannot succeed. The job never ends
 * in `failed_jobs`: an outage would otherwise park every browser envelope
 * there, body and all. Its failures are never self-captured either.
 */
class ForwardEnvelope implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $upstream,
        public readonly string $body,
        public readonly array $headers,
    ) {}

    public function handle(EnvelopeForwarder $forwarder): void
    {
        try {
            $status = $forwarder->forward($this->upstream, $this->body, $this->headers)->getStatusCode();
        } catch (GuzzleException $e) {
            $this->retryOrGiveUp($e->getMessage());

            return;
        }

        if ($status >= 500) {
            $this->retryOrGiveUp("upstream answered {$status}");
        }
    }

    private function retryOrGiveUp(string $reason): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);

            return;
        }

        Log::warning('watchtower: async forward failed', [
            'upstream' => $this->upstream,
            'attempts' => $this->attempts(),
            'message' => $reason,
        ]);
    }
}
