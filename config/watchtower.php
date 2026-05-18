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

    'before_send' => [
        'enabled' => filter_var(env('WATCHTOWER_BEFORE_SEND', true), FILTER_VALIDATE_BOOL),

        // Exceptions in this list are dropped at the SDK before egress — they
        // never reach Watchtower. Extend per project; don't subtract unless
        // you actually want validation / auth-fail / 404 noise in the inbox.
        'ignored_exceptions' => [
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Illuminate\Session\TokenMismatchException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
            \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
            \Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException::class,
        ],

        // Case-insensitive keys to redact from event.request.data /
        // event.request.headers / event.extra. Values are replaced with
        // '[Filtered]'. The credit-card-shape regex is always applied on top.
        'scrub_keys' => [
            'password', 'password_confirmation', 'current_password',
            'token', 'api_key', 'secret', 'authorization', 'cookie',
            'credit_card', 'card', 'cvv', 'cvc',
        ],
    ],
];
