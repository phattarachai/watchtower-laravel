<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as HttpRequest;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;
use Sentry\Util\Http;

/**
 * The Sentry SDK's HTTP client, short-circuited into this app's own kernel —
 * loopback self-capture exactly as it runs in production, minus the socket.
 */
final class KernelHttpClient implements HttpClientInterface
{
    public function sendRequest(Request $request, Options $options): Response
    {
        $dsn = $options->getDsn();

        if ($dsn === null) {
            return new Response(400, [], 'no DSN');
        }

        $server = ['CONTENT_TYPE' => 'application/x-sentry-envelope'];

        foreach (Http::getRequestHeaders($dsn, 'sentry.php', '4.0.0') as $line) {
            [$name, $value] = array_map(trim(...), explode(':', (string) $line, 2));
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = app(Kernel::class)->handle(
            HttpRequest::create($dsn->getEnvelopeApiEndpointUrl(), 'POST', server: $server, content: (string) $request->getStringBody()),
        );

        return new Response($response->getStatusCode(), [], '');
    }
}
