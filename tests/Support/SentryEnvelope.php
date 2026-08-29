<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests\Support;

use Illuminate\Support\Str;

final class SentryEnvelope
{
    /**
     * Build a valid Sentry envelope v7 body carrying one `event` item.
     *
     * @param  array<string, mixed>  $eventOverrides
     * @param  array<string, mixed>  $headerOverrides  merged into the envelope header (tunnel mode uses `dsn`)
     * @param  list<array{type: string, payload: array<string, mixed>}>  $extraItems
     */
    public static function build(array $eventOverrides = [], array $headerOverrides = [], array $extraItems = []): string
    {
        $event = self::eventPayload($eventOverrides);

        $header = array_replace([
            'event_id' => $event['event_id'],
            'sent_at' => now()->toIso8601String(),
            'sdk' => $event['sdk'],
        ], $headerOverrides);

        $lines = [
            json_encode($header, JSON_THROW_ON_ERROR),
            self::itemHeader('event', $event),
            json_encode($event, JSON_THROW_ON_ERROR),
        ];

        foreach ($extraItems as $extra) {
            $lines[] = self::itemHeader($extra['type'], $extra['payload']);
            $lines[] = json_encode($extra['payload'], JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines);
    }

    /**
     * The normalized event payload as it lands in `watchtower_events.payload`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function eventPayload(array $overrides = []): array
    {
        return array_replace_recursive(self::defaultEvent(), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultEvent(): array
    {
        return [
            'event_id' => str_replace('-', '', (string) Str::uuid()),
            'timestamp' => now()->toIso8601String(),
            'platform' => 'php',
            'level' => 'error',
            'environment' => 'testing',
            'release' => '1.0.0',
            'sdk' => ['name' => 'sentry.php.laravel', 'version' => '4.0.0'],
            'exception' => [
                'values' => [
                    [
                        'type' => 'RuntimeException',
                        'value' => 'Something exploded',
                        'stacktrace' => [
                            'frames' => [
                                ['filename' => 'vendor/laravel/framework/src/Foundation/run.php', 'function' => 'fire', 'lineno' => 12, 'in_app' => false],
                                [
                                    'filename' => 'app/Http/Controllers/HomeController.php',
                                    'function' => 'index',
                                    'lineno' => 42,
                                    'in_app' => true,
                                    'pre_context' => ['    public function index()', '    {'],
                                    'context_line' => '        throw new RuntimeException("Something exploded");',
                                    'post_context' => ['    }'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'request' => [
                'url' => 'https://apps.test/checkout',
                'method' => 'POST',
                'headers' => ['user-agent' => 'Mozilla/5.0'],
                'data' => ['coupon' => 'SUMMER'],
            ],
            'user' => ['id' => '77', 'email' => 'buyer@example.test', 'username' => 'buyer'],
            'tags' => ['route' => 'checkout.store', 'server_name' => 'web-01'],
            'contexts' => ['runtime' => ['name' => 'php', 'version' => '8.4.0']],
            'breadcrumbs' => [
                'values' => [
                    ['category' => 'query', 'message' => 'select * from orders', 'level' => 'info', 'timestamp' => 1735689600],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function itemHeader(string $type, array $payload): string
    {
        return json_encode([
            'type' => $type,
            'length' => strlen(json_encode($payload, JSON_THROW_ON_ERROR)),
            'content_type' => 'application/json',
        ], JSON_THROW_ON_ERROR);
    }
}
