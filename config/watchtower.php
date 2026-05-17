<?php

declare(strict_types=1);

return [
    'dsn' => env('WATCHTOWER_DSN', env('SENTRY_LARAVEL_DSN')),

    'relay' => [
        'enabled' => env('WATCHTOWER_RELAY_ENABLED', true),
        'path'    => env('WATCHTOWER_RELAY_PATH', '/api/watchtower-relay'),
        'timeout' => (int) env('WATCHTOWER_RELAY_TIMEOUT', 5),
        'async'   => filter_var(env('WATCHTOWER_RELAY_ASYNC', false), FILTER_VALIDATE_BOOL),
        'queue'   => env('WATCHTOWER_RELAY_QUEUE'),
    ],

    'forwarder' => [
        'verify_ssl'      => filter_var(env('WATCHTOWER_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'connect_timeout' => (float) env('WATCHTOWER_CONNECT_TIMEOUT', 3),
    ],
];
