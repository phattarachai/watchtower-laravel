<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * The one place an envelope leaves this app for the upstream Watchtower, used
 * by the sync relay and by ForwardEnvelope.
 *
 * Bound as a singleton so its Guzzle client — and the curl handles Guzzle keeps
 * behind it — survive between forwards: a queue worker or an Octane worker then
 * reuses the upstream TLS connection instead of handshaking once per envelope.
 * Under PHP-FPM the singleton lives for one request, which is no worse than the
 * old per-call client.
 *
 * Bodies the browser sent uncompressed are gzipped on the way out; every
 * Watchtower relay endpoint inflates `Content-Encoding: gzip`.
 */
class EnvelopeForwarder
{
    private const int GZIP_MIN_BYTES = 1_024;

    private ?Client $client = null;

    /**
     * @param  array<string, string>  $headers
     *
     * @throws GuzzleException
     */
    public function forward(string $upstream, string $body, array $headers, ?int $timeout = null): ResponseInterface
    {
        [$body, $headers] = $this->compress($body, $headers);

        return $this->client()->post($upstream, [
            'headers' => $headers,
            'body' => $body,
            'http_errors' => false,
            'timeout' => $timeout ?? (int) config('watchtower.relay.timeout', 5),
            'connect_timeout' => (float) config('watchtower.forwarder.connect_timeout', 3),
            'verify' => (bool) config('watchtower.forwarder.verify_ssl', true),
        ]);
    }

    private function client(): Client
    {
        return $this->client ??= app(Client::class);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: string, 1: array<string, string>}
     */
    private function compress(string $body, array $headers): array
    {
        $eligible = (bool) config('watchtower.forwarder.gzip', true)
            && strlen($body) >= self::GZIP_MIN_BYTES
            && ! isset($headers['Content-Encoding']);

        $compressed = $eligible ? gzencode($body, 6) : false;

        if ($compressed === false) {
            return [$body, $headers];
        }

        return [$compressed, [...$headers, 'Content-Encoding' => 'gzip']];
    }
}
