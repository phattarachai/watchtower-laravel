<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Phattarachai\WatchtowerLaravel\Jobs\ForwardEnvelope;
use Phattarachai\WatchtowerLaravel\Support\Dsn;

class RelayController
{
    public const VERSION = '0.1.0';

    private const PASSTHROUGH_REQUEST_HEADERS = ['Content-Type', 'Content-Encoding'];

    private const PASSTHROUGH_RESPONSE_HEADERS = [
        'Content-Type',
        'X-Sentry-Rate-Limits',
        'X-Sentry-Rate-Limits-Remaining',
        'Retry-After',
    ];

    public function __invoke(Request $request): Response|JsonResponse
    {
        $relayPath = (string) config('watchtower.relay.path', '/api/watchtower-relay');
        $parsed    = Dsn::parse(config('watchtower.dsn'), $relayPath);

        if ($parsed === null) {
            return new JsonResponse(['error' => 'watchtower_dsn_missing'], 503);
        }

        $upstream = $parsed['scheme'].'://'.$parsed['host_with_port'].$relayPath;
        $body     = $request->getContent();
        $headers  = $this->forwardedHeaders($request);

        if ((bool) config('watchtower.relay.async', false)) {
            $job   = new ForwardEnvelope($upstream, $body, $headers);
            $queue = config('watchtower.relay.queue');

            if ($queue !== null && $queue !== '') {
                $job->onQueue($queue);
            }

            dispatch($job);

            return new JsonResponse(['queued' => true], 202);
        }

        return $this->forwardSync($upstream, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function forwardSync(string $upstream, string $body, array $headers): Response|JsonResponse
    {
        $client = app(Client::class);

        try {
            $response = $client->post($upstream, [
                'headers'         => $headers,
                'body'            => $body,
                'http_errors'     => false,
                'timeout'         => (int) config('watchtower.relay.timeout', 5),
                'connect_timeout' => (float) config('watchtower.forwarder.connect_timeout', 3),
                'verify'          => (bool) config('watchtower.forwarder.verify_ssl', true),
            ]);
        } catch (GuzzleException $e) {
            return new JsonResponse([
                'error'   => 'upstream_unreachable',
                'message' => $e->getMessage(),
            ], 502);
        }

        $passthrough = [];

        foreach (self::PASSTHROUGH_RESPONSE_HEADERS as $name) {
            $value = $response->getHeaderLine($name);

            if ($value !== '') {
                $passthrough[$name] = $value;
            }
        }

        return new Response(
            (string) $response->getBody(),
            $response->getStatusCode(),
            $passthrough,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function forwardedHeaders(Request $request): array
    {
        $headers = [
            'User-Agent' => 'WatchtowerLaravel/'.self::VERSION,
        ];

        foreach (self::PASSTHROUGH_REQUEST_HEADERS as $name) {
            $value = $request->headers->get($name);

            if ($value !== null && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
