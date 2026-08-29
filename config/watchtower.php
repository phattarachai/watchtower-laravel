<?php

declare(strict_types=1);
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [
    'dsn' => env('WATCHTOWER_DSN', env('SENTRY_LARAVEL_DSN')),

    // relay      — forward every envelope to the central Watchtower server.
    // standalone — store and process events in this app's own database.
    // dual       — store locally AND forward upstream.
    'mode' => env('WATCHTOWER_MODE', 'relay'),

    'server' => [
        // null = the host application's default database connection.
        'connection' => env('WATCHTOWER_DB_CONNECTION'),

        // URL prefix for the embedded ingest endpoints and (later) the UI.
        // A project DSN reads `scheme://key@host/<path>/<project id>` — the
        // Sentry SDK appends `/api/<project id>` to the prefix itself.
        'path' => env('WATCHTOWER_PATH', 'watchtower'),

        'retention_days' => (int) env('WATCHTOWER_RETENTION_DAYS', 90),

        // How this app's own exceptions reach the embedded store.
        // 'transport' — swap the Sentry SDK transport for an in-process one:
        //               no HTTP request, no queue worker needed to ingest.
        // 'loopback'  — leave the SDK's HTTP transport alone; the event travels
        //               over the network back into this app's ingest route.
        // false       — do not self-capture at all.
        'self_capture' => env('WATCHTOWER_SELF_CAPTURE', 'transport'),

        // The embedded MCP server mounted at /{path}/mcp. Requires laravel/mcp;
        // the provider skips registration when the package is absent.
        'mcp' => [
            'enabled' => filter_var(env('WATCHTOWER_MCP_ENABLED', true), FILTER_VALIDATE_BOOL),
            'middleware' => ['throttle:60,1'],
        ],

        'queue' => [
            'connection' => env('WATCHTOWER_QUEUE_CONNECTION'),
            'name' => env('WATCHTOWER_QUEUE_NAME'),
        ],

        // The embedded Inertia UI mounted under the same `path` prefix. The
        // AuthorizeUi middleware is always appended by the service provider,
        // so the gate can never be forgotten by editing `middleware`.
        'ui' => [
            'enabled' => filter_var(env('WATCHTOWER_UI_ENABLED', true), FILTER_VALIDATE_BOOL),

            'domain' => env('WATCHTOWER_UI_DOMAIN'),

            'middleware' => ['web'],

            // Where a *guest* is sent when the gate says no. A route name
            // (preferred) or a URL; null 403s instead, and so does a
            // signed-in user the gate still rejects.
            'redirect_guests_to' => env('WATCHTOWER_UI_LOGIN_ROUTE', 'login'),
        ],

        'rate_limit_per_min' => (int) env('WATCHTOWER_RATE_LIMIT_PER_MIN', 300),

        'max_payload_bytes' => (int) env('WATCHTOWER_MAX_PAYLOAD_BYTES', 1_048_576),

        'ingest' => [
            // Top-level keys retained from a Sentry event payload. Anything
            // else is dropped before the row is written.
            'allowed_event_fields' => [
                'event_id', 'timestamp', 'platform', 'level', 'logger',
                'transaction', 'server_name', 'release', 'environment',
                'message', 'exception', 'request', 'user', 'contexts',
                'tags', 'extra', 'breadcrumbs', 'sdk', 'fingerprint',
            ],

            'allowed_context_keys' => [
                'os', 'runtime', 'browser', 'device',
                'laravel', 'livewire', 'job', 'trace',
            ],

            'scrub' => [
                'header_keys' => ['cookie', 'authorization', 'x-csrf-token', 'x-api-key', 'x-auth-token'],
                'body_keys' => ['password', 'pwd', 'passwd', 'token', 'secret', 'api_key', 'access_token', 'refresh_token', 'authorization'],
                'placeholder' => '[Filtered]',
            ],
        ],
    ],

    'relay' => [
        'enabled' => env('WATCHTOWER_RELAY_ENABLED', true),
        'path' => env('WATCHTOWER_RELAY_PATH', '/api/watchtower-relay'),
        'timeout' => (int) env('WATCHTOWER_RELAY_TIMEOUT', 5),
        'async' => filter_var(env('WATCHTOWER_RELAY_ASYNC', false), FILTER_VALIDATE_BOOL),
        'queue' => env('WATCHTOWER_RELAY_QUEUE'),
    ],

    'forwarder' => [
        'verify_ssl' => filter_var(env('WATCHTOWER_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
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
            ValidationException::class,
            AuthenticationException::class,
            AuthorizationException::class,
            ModelNotFoundException::class,
            TokenMismatchException::class,
            NotFoundHttpException::class,
            MethodNotAllowedHttpException::class,
            AccessDeniedHttpException::class,
            SuspiciousOperationException::class,
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
