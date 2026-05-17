<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ForwardEnvelope implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $upstream,
        public readonly string $body,
        public readonly array $headers,
    ) {}

    public function handle(?Client $client = null): void
    {
        $client ??= app(Client::class);

        $timeout       = (int) config('watchtower.relay.timeout', 5);
        $verifySsl     = (bool) config('watchtower.forwarder.verify_ssl', true);
        $connectTimeout = (float) config('watchtower.forwarder.connect_timeout', 3);

        try {
            $client->post($this->upstream, [
                'headers'         => $this->headers,
                'body'            => $this->body,
                'http_errors'     => false,
                'timeout'         => $timeout,
                'connect_timeout' => $connectTimeout,
                'verify'          => $verifySsl,
            ]);
        } catch (GuzzleException $e) {
            Log::warning('watchtower: async forward failed', [
                'upstream' => $this->upstream,
                'message'  => $e->getMessage(),
            ]);
        }
    }
}
