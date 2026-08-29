# Watchtower Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/watchtower-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/watchtower-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/watchtower-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/watchtower-laravel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/watchtower-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/watchtower-laravel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/watchtower-laravel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/watchtower-laravel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/watchtower-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/watchtower-laravel)

Laravel client for [Watchtower](https://github.com/phattarachai/watchtower), a self-hosted Sentry-compatible exception tracker. Installs and configures `sentry/sentry-laravel`, wires `Integration::handles($exceptions)` into `bootstrap/app.php`, and exposes a same-origin browser tunnel (`/api/watchtower-relay`) that proxies envelopes to your Watchtower instance — dodging ad-blockers that strip `?sentry_key=` query strings.

## Install

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install --dsn=http://your-public-key@your-watchtower-host/42
```

The install command:

1. Validates and writes `WATCHTOWER_DSN` and `SENTRY_LARAVEL_DSN` to `.env`.
2. Patches `bootstrap/app.php` to call `Sentry\Laravel\Integration::handles($exceptions)` inside `withExceptions(...)`.
3. Publishes `config/watchtower.php`.
4. If `vite.config.{js,ts}` is present, writes `VITE_SENTRY_DSN`, `VITE_SENTRY_TUNNEL`, and `VITE_SENTRY_ENVIRONMENT` and prints a Vite entry snippet.
5. If the `claude` (Claude Code) CLI is on PATH, registers the Watchtower MCP server so Claude can query and triage issues directly. Pass `--no-mcp` to skip.

Re-running is idempotent. Pass `--dry-run` to preview changes.

## Standalone mode

Standalone drops the central Watchtower server entirely: this app owns the tables, the ingest endpoint, the issue UI
and an MCP server of its own.

```bash
php artisan watchtower:install --standalone
```

On top of the steps above it:

1. Writes `WATCHTOWER_MODE=standalone` and runs `php artisan migrate --force` to create the `watchtower_*` tables.
2. Creates the first project (named after `APP_NAME`) and points `SENTRY_LARAVEL_DSN` at its DSN.
3. Publishes `resources/js/pages/Watchtower.jsx`, adds the `@watchtower` Vite alias, and appends
   `@import 'tailwindcss' prefix(tw);` plus the package `@source` line to `resources/css/app.css`.
4. Prints the authorization snippet and registers the embedded MCP server at `/watchtower/mcp` with Claude Code.

`--mode=dual` does all of that and keeps forwarding browser envelopes to a central Watchtower as well, so it still
prompts for the upstream DSN.

The two build-tool edits the package cannot make for you if your project deviates from the default layout:

```js
// vite.config.js
resolve: {
    alias: {
        '@watchtower': './vendor/phattarachai/watchtower-laravel/resources/js/watchtower',
    },
},
```

```css
/* resources/css/app.css */
@import 'tailwindcss' prefix(tw);
@source '../../vendor/phattarachai/watchtower-laravel/resources/js/watchtower/**/*.jsx';
```

Only the page stub lives in your tree — the module itself is reached through the alias, so there is no second copy to
drift. Authorize the UI from a service provider:

```php
Watchtower::auth(fn ($request): bool => $request->user()?->isAdmin() === true);
```

Then verify everything with `php artisan watchtower:doctor`, which checks the tables, the routes, both build-tool
edits, the mailer, the queue, the MCP registration and the self-capture path, and names whatever is missing.

### Embedded commands

| Command                                     | Purpose                                                             |
| ------------------------------------------- | ------------------------------------------------------------------- |
| `watchtower:doctor`                         | Report every host-app requirement, green or red.                    |
| `watchtower:project list`                   | Projects with masked keys and full DSNs.                            |
| `watchtower:project create "Name"`          | New project; prints its DSN. `--platform=` to override `laravel`.   |
| `watchtower:project rotate-key {id\|slug}`   | Issue a fresh public key.                                           |
| `watchtower:project activate/deactivate`    | Stop or resume accepting events for one project.                    |
| `watchtower:prune`                          | Drop events past retention (scheduled daily on its own).            |

### Self-capture

By default this app's own exceptions never leave the process: the Sentry SDK's HTTP transport is swapped for an
in-process one that hands the serialized envelope straight to the ingest pipeline, `before_send` scrubbing intact.
Set `WATCHTOWER_SELF_CAPTURE=loopback` to keep the SDK's HTTP transport (events travel over the network back into
this app's ingest route), or `false` to disable self-capture entirely.

### MCP

Install `laravel/mcp` and the server mounts at `/{prefix}/mcp`, authenticated with any active project's public key —
`Authorization: Bearer {public_key}` or `?api_key=`. Every tool is scoped to that project. The tools mirror the
central server: `list_issues`, `get_issue`, `list_events`, `get_event`, `get_stats`, `resolve_issue`, `ignore_issue`,
`unresolve_issue`, `snooze_issue`.

## Configuration

| Env key                       | Default                     | Purpose                                                          |
| ----------------------------- | --------------------------- | ---------------------------------------------------------------- |
| `WATCHTOWER_DSN`              | falls back to `SENTRY_LARAVEL_DSN` | Watchtower DSN: `http://{key}@{host}/{numeric-project-id}`. |
| `WATCHTOWER_MODE`             | `relay`                     | `relay`, `standalone` or `dual`.                                 |
| `WATCHTOWER_PATH`             | `watchtower`                | URL prefix for the embedded ingest, UI and MCP endpoints.        |
| `WATCHTOWER_DB_CONNECTION`    | _(default connection)_      | Connection the `watchtower_*` tables live on.                    |
| `WATCHTOWER_RETENTION_DAYS`   | `90`                        | Event retention; a project row may override it.                  |
| `WATCHTOWER_SELF_CAPTURE`     | `transport`                 | `transport`, `loopback` or `false`.                              |
| `WATCHTOWER_MCP_ENABLED`      | `true`                      | Mount the embedded MCP server (needs `laravel/mcp`).             |
| `WATCHTOWER_RELAY_ENABLED`    | `true`                      | Register the relay route on boot.                                |
| `WATCHTOWER_RELAY_PATH`       | `/api/watchtower-relay`     | Relay endpoint path (must live under `/api/`).                   |
| `WATCHTOWER_RELAY_TIMEOUT`    | `5`                         | Upstream request timeout (seconds).                              |
| `WATCHTOWER_RELAY_ASYNC`      | `false`                     | Forward envelopes through a queued job instead of sync.          |
| `WATCHTOWER_RELAY_QUEUE`      | _(default queue)_           | Queue name when async is enabled.                                |
| `WATCHTOWER_VERIFY_SSL`       | `true`                      | Verify upstream TLS certificate.                                 |
| `WATCHTOWER_CONNECT_TIMEOUT`  | `3`                         | Guzzle connect timeout (seconds).                                |

## Browser side

The browser SDK posts envelopes to your own app at `/api/watchtower-relay`. The relay parses your configured DSN, forwards the request body verbatim to `{scheme}://{host_with_port}/api/watchtower-relay` on the Watchtower instance, and passes back the upstream status and rate-limit headers.

`watchtower:install` publishes a small helper to `resources/js/vendor/watchtower.js` (plus `resources/js/vendor/livewire.js`, the Livewire `beforeSend` rules it imports) that wraps `Sentry.init(...)` with the Watchtower-tuned defaults (same-origin tunnel, no PII, browser-extension `denyUrls`) and applies `<meta name="watchtower-user-*">` to `Sentry.setUser(...)`. Per Vite entry:

```js
import { initWatchtower } from './vendor/watchtower.js';

initWatchtower();
```

The Sentry config lives inside the published helper, so multiple entries don't duplicate it. Customize options (e.g. `ignoreErrors`) there once.

In your root Blade layout's `<head>` add the package directive that emits the user-context meta tags:

```blade
@watchtowerUser
```

`@watchtowerUser` is registered automatically by the service provider and compiles to three `<meta name="watchtower-user-{id,email,name}">` tags. Customize by publishing the view: `php artisan vendor:publish --tag=watchtower-views`.

For Filament admin panels (which bypass the root Blade layout), register a render hook:

```php
$panel->renderHook(
    PanelsRenderHook::HEAD_END,
    fn (): string => Blade::render('@watchtowerUser'),
);
```

Because the request hits your own origin under `/api/`, ad-blockers don't recognize it as Sentry traffic.

## Async forwarding

Set `WATCHTOWER_RELAY_ASYNC=true` to dispatch each forward through a `ForwardEnvelope` job. The relay returns `202 {"queued": true}` immediately and the worker performs the upstream POST. Failures are logged but not retried beyond Guzzle's default behavior — the Sentry SDK retransmits anyway.

## Verify

```bash
php artisan watchtower:test
```

Prints the resolved config, runs `sentry:test`, and POSTs a synthetic envelope through the relay path.

## Troubleshooting

The bundled skill at `vendor/phattarachai/watchtower-laravel/resources/boost/skills/watchtower-error-tracking/reference.md` covers every install + verify + triage path, including the MCP server. To install it into Claude's skill set: `php artisan boost:install --skills`.

## License

MIT.
