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

    'user_context' => [
        'enabled' => filter_var(env('WATCHTOWER_USER_CONTEXT', true), FILTER_VALIDATE_BOOL),

        // 'auto' walks every guard configured in config/auth.php. Override with
        // a comma-separated list (e.g. 'admin,web') to control priority — the
        // first authenticated guard wins.
        'guards' => env('WATCHTOWER_USER_CONTEXT_GUARDS', 'auto'),

        // Explicit list — subset of: id, email, name, ip_address. Drop fields
        // here to opt out of attaching them to Sentry's user scope.
        //
        // Empty array [] means "auto-discover": send every attribute on the
        // Eloquent user model EXCEPT (a) anything in the model's $hidden array
        // and (b) the package's built-in deny-list (password, remember_token,
        // two_factor_*, api_token, password_hash). Sentry maps id/email/
        // username natively; other columns land in user.metadata.
        'fields' => [],
    ],
];
