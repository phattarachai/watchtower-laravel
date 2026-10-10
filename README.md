# Watchtower Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/watchtower-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/watchtower-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/watchtower-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/watchtower-laravel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/watchtower-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/watchtower-laravel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/watchtower-laravel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/watchtower-laravel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/watchtower-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/watchtower-laravel)

Sentry-compatible error tracking that runs inside your own Laravel app. In **standalone mode** the app keeps its
exceptions in its own `watchtower_*` tables, takes events from the stock Sentry SDKs on its own ingest endpoint,
shows them in an issue inbox at `/watchtower`, emails alerts, and serves an MCP server so Claude Code can triage
issues in the conversation. You don't need a second server or a SaaS account.

Reporting goes through the official [`sentry/sentry-laravel`](https://github.com/getsentry/sentry-laravel) and
`@sentry/browser` SDKs, so anything that speaks the Sentry protocol can report here too.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- A database for the `watchtower_*` tables (SQLite, MySQL and Postgres all work)
- A queue worker in production. The `sync` queue works, but then each event is processed during the request that
  reported it.
- [`laravel/mcp`](https://github.com/laravel/mcp), optional, for the MCP server

The bundled issue UI is an Inertia page, so it needs an **Inertia + React** host:

- `inertiajs/inertia-laravel` (^2.0 or ^3.0) with `@inertiajs/react` and React
- Tailwind CSS v4 through Vite. The UI compiles its own stylesheet (`resources/css/watchtower.css`) with every
  class under the `tw:` prefix, so it never collides with the app's classes, and the app's Tailwind config isn't
  touched.
- The `@watchtower` alias in `vite.config.{js,ts}`

`inertiajs/inertia-laravel` is suggested, not required. **Livewire, Filament and Blade apps** can use everything
except the bundled UI page: standalone ingest, self-capture, alerts and the MCP server, as well as relay mode. Set
`WATCHTOWER_UI_ENABLED=false` and triage through MCP.

## Install

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install --standalone
```

The install command:

1. Writes `WATCHTOWER_MODE=standalone` and runs `php artisan migrate --force` to create the `watchtower_*` tables.
2. Creates the first project (named after `APP_NAME`) and points `SENTRY_LARAVEL_DSN` at its DSN.
3. Asks whether to send PII (`SENTRY_SEND_DEFAULT_PII`, off by default), adds breadcrumb env keys, and patches
   `bootstrap/app.php` to call `Sentry\Laravel\Integration::handles($exceptions)` inside `withExceptions(...)`.
4. Publishes `config/watchtower.php`.
5. With Inertia installed: publishes `resources/js/pages/Watchtower.jsx` (`.tsx` in a TypeScript app), adds the
   `@watchtower` Vite alias and writes `resources/css/watchtower.css`. Without Inertia it skips these files and
   tells you how to add the UI later.
6. With a Vite config: writes `VITE_SENTRY_DSN`, `VITE_SENTRY_TUNNEL` and `VITE_SENTRY_ENVIRONMENT` and publishes
   the browser helper (see [Browser errors](#browser-errors)).
7. If the `claude` (Claude Code) CLI is on PATH, registers the app's MCP server in a project-scoped `.mcp.json`.
   Pass `--no-mcp` to skip.

Re-running it is safe. Pass `--dry-run` to preview the changes.

If your project doesn't use the default layout, these are the two build-tool edits the package can't make for you:

```js
// vite.config.js
resolve: {
    alias: {
        '@watchtower': './vendor/phattarachai/watchtower-laravel/resources/js/watchtower',
    },
},
```

```css
/* resources/css/watchtower.css (imported by the published page) */
@import 'tailwindcss' prefix(tw) source(none);
@source '../../vendor/phattarachai/watchtower-laravel/resources/js/watchtower/**/*.jsx';
@custom-variant dark (&:where(.dark, .dark *));
```

Only the page stub is copied into your app. The module itself is loaded through the alias, so you never have a
second copy that drifts out of date.

The UI follows your app's dark mode: it turns dark when an ancestor has the `.dark` class, which is how the Laravel
starter kits do it. Set `WATCHTOWER_UI_THEME=light` or `dark` to pin it. Writes send the `csrf-token` meta tag when
your layout has one, and otherwise the `XSRF-TOKEN` cookie that Laravel sets on every `web` response, so a layout
without the meta tag (the React starter kit's) works as-is.

## Authorize the UI

In the `local` environment the UI is open. Everywhere else, grant access from a service provider:

```php
use Phattarachai\WatchtowerLaravel\Watchtower;

Watchtower::auth(fn ($request): bool => $request->user()?->isAdmin() === true);
```

Or define a `viewWatchtower` gate instead. A guest who fails the check is redirected to the `login` route
(`WATCHTOWER_UI_LOGIN_ROUTE`). A signed-in user who fails it gets a 403.

## Check the install

```bash
php artisan watchtower:doctor
```

The doctor checks the tables, the routes, the UI wiring (Inertia, the page stub, the Vite alias, the Tailwind
lines), the mailer, the queue and Horizon, Redis memory, the MCP server and the self-capture path. Anything missing
is reported by name. It exits non-zero while something still needs fixing, so you can run it in CI.

## Commands

| Command                                     | Purpose                                                             |
| ------------------------------------------- | ------------------------------------------------------------------- |
| `watchtower:doctor`                         | Report every host-app requirement, green or red.                    |
| `watchtower:project list`                   | Projects with masked keys and full DSNs.                            |
| `watchtower:project create "Name"`          | New project; prints its DSN. `--platform=` to override `laravel`.   |
| `watchtower:project rotate-key {id\|slug}`  | Issue a fresh public key.                                           |
| `watchtower:project activate/deactivate`    | Stop or resume accepting events for one project.                    |
| `watchtower:prune`                          | Drop events past retention (scheduled daily on its own).            |
| `watchtower:test`                           | Print the resolved config and send a test exception and envelope.  |

A project is anything that reports here. The first one is this app. Other services (a WordPress site, a Next.js
frontend, a worker on another box) get a project each, and their stock Sentry SDK reports to that project's DSN:
`https://{public_key}@your-app.test/watchtower/{project_id}`. The SDK adds `/api/{project_id}/envelope/` itself,
so the DSN has no `api` segment of its own.

## Self-capture

By default this app's own exceptions never leave the process. The Sentry SDK's HTTP transport is swapped for an
in-process one that hands the serialized envelope straight to the ingest pipeline, with `before_send` scrubbing
still applied. Set `WATCHTOWER_SELF_CAPTURE=loopback` to keep the SDK's HTTP transport (events then travel over the
network back into this app's ingest route), or `false` to turn self-capture off.

Watchtower never self-captures its own queue failures. Anything a worker reports while running or failing a
`ProcessEventJob` or `ForwardEnvelope` is dropped, so a failing job can't re-queue itself as a new event.

## Browser errors

The browser SDK posts to your own origin at `/api/watchtower-relay`, so ad blockers that strip Sentry traffic
don't catch it. In standalone mode that route ingests the envelope locally.

`watchtower:install` publishes a small helper to `resources/js/vendor/watchtower.js` (plus
`resources/js/vendor/livewire.js`, the Livewire `beforeSend` rules it imports). The helper wraps `Sentry.init(...)`
with Watchtower's defaults: the same-origin tunnel, no PII, and `denyUrls` for browser extensions. It also passes the
`<meta name="watchtower-user-*">` tags to `Sentry.setUser(...)`. Call it once per Vite entry:

```js
import { initWatchtower } from './vendor/watchtower.js';

initWatchtower();
```

Then add the directive that emits the user meta tags to your root Blade layout's `<head>`:

```blade
@watchtowerUser
```

Filament panels skip the root layout, so register a render hook there instead:

```php
$panel->renderHook(
    PanelsRenderHook::HEAD_END,
    fn (): string => Blade::render('@watchtowerUser'),
);
```

Publish the view with `php artisan vendor:publish --tag=watchtower-views` to customize it.

## Production

Every ingest path (HTTP, the browser route and self-capture) shares a per-project budget
(`WATCHTOWER_RATE_LIMIT_PER_MIN`) and a per-issue one (`WATCHTOWER_RATE_LIMIT_PER_FINGERPRINT_PER_MIN`). Events over
budget are counted on their issue but not queued. Once `WATCHTOWER_MAX_QUEUE_DEPTH` jobs are waiting, new events are
counted instead of queued. Each event is scrubbed (SQL row values included) and trimmed to
`WATCHTOWER_MAX_EVENT_BYTES` (200 KB) before it is queued, by the pipeline in
[`phattarachai/watchtower-core`](https://github.com/phattarachai/watchtower-core).

On Redis and Horizon, give events a dedicated queue. Add the supervisor first, then point Watchtower at it:

```php
// config/horizon.php → 'defaults' (and list it under each environment)
'app-supervisor-watchtower' => [
    'connection' => 'redis',
    'queue' => ['watchtower'],
    'maxProcesses' => 1,
    'tries' => 3,
    'timeout' => 60,
],
```

```dotenv
WATCHTOWER_QUEUE_NAME=watchtower
```

`watchtower:doctor` fails if no Horizon supervisor consumes that queue, and warns about a Redis with no memory cap or
one that evicts keys. Cap Redis (`maxmemory 1gb`, `maxmemory-policy noeviction`) so a runaway makes writes fail
instead of getting Redis OOM-killed. The bundled skill's `reference.md` has the full Horizon block and a triage
runbook for a queue that is already flooded.

Alert mail is queued on the same connection and queue, so the worker has to be running for alerts to go out. Rules
are set per project in the UI (Alerts) and go to the addresses listed on each rule.

## MCP

Install `laravel/mcp` and the server is mounted at `/{prefix}/mcp`. It authenticates with any active project's public
key, as `Authorization: Bearer {public_key}` or `?api_key=`, and every tool is scoped to that project:
`list_issues`, `get_issue`, `list_events`, `get_event`, `get_stats`, `resolve_issue`, `ignore_issue`,
`unresolve_issue`, `snooze_issue`.

```bash
claude mcp add --transport http --scope project watchtower https://your-app.test/watchtower/mcp \
  --header "Authorization: Bearer {public_key}"
```

`watchtower:install` runs this for you when the `claude` CLI is on PATH.

## Relay and dual mode

The package can also report to a separate, central **Watchtower server**, a self-hosted instance that collects
events from many apps. These modes are for teams that run one:

- **`relay`** (the default when you install without `--standalone`): the app has no tables or UI. The backend SDK
  reports to the server's DSN, and the browser route `/api/watchtower-relay` forwards envelopes to
  `/api/watchtower-relay` on that server.
- **`dual`**: standalone, plus browser envelopes are also forwarded to the central server.

```bash
php artisan watchtower:install --dsn=https://{public_key}@watchtower.example.com/42
php artisan watchtower:install --mode=dual --dsn=https://{public_key}@watchtower.example.com/42
```

The browser relay forwards to an endpoint that only a Watchtower server has, so a relay DSN can't point at sentry.io
or another Sentry host. To report to Sentry itself, use `sentry/sentry-laravel` on its own.

Set `WATCHTOWER_RELAY_ASYNC=true` to forward through a queued `ForwardEnvelope` job. The relay then returns
`202 {"queued": true}` straight away, and the worker does the upstream POST. An unreachable upstream or a 5xx is
retried twice with backoff. A 4xx (including 429) is dropped. After the last attempt the failure is logged and the
job completes, so an outage never leaves envelope bodies in `failed_jobs`.

## Configuration

| Env key                       | Default                     | Purpose                                                          |
| ----------------------------- | --------------------------- | ---------------------------------------------------------------- |
| `WATCHTOWER_MODE`             | `relay`                     | `standalone`, `relay` or `dual`. Install writes it.              |
| `WATCHTOWER_DSN`              | falls back to `SENTRY_LARAVEL_DSN` | Central server DSN (relay/dual): `https://{key}@{host}/{numeric-project-id}`. |
| `WATCHTOWER_PATH`             | `watchtower`                | URL prefix for the ingest, UI and MCP endpoints.                 |
| `WATCHTOWER_DB_CONNECTION`    | _(default connection)_      | Connection the `watchtower_*` tables live on.                    |
| `WATCHTOWER_RETENTION_DAYS`   | `90`                        | Event retention; a project row may override it.                  |
| `WATCHTOWER_SELF_CAPTURE`     | `transport`                 | `transport`, `loopback` or `false`.                              |
| `WATCHTOWER_UI_ENABLED`       | `true`                      | Mount the issue UI (also needs `inertiajs/inertia-laravel`).     |
| `WATCHTOWER_UI_DOMAIN`        | _(none)_                    | Serve the UI only on this domain.                                |
| `WATCHTOWER_UI_LOGIN_ROUTE`   | `login`                     | Route name or URL guests are redirected to; empty 403s instead.  |
| `WATCHTOWER_UI_THEME`         | `auto`                      | `auto` goes dark under the host's `.dark` class; `light` or `dark` pins it. |
| `WATCHTOWER_MCP_ENABLED`      | `true`                      | Mount the MCP server (needs `laravel/mcp`).                      |
| `WATCHTOWER_QUEUE_CONNECTION` | _(default connection)_      | Queue connection for `ProcessEventJob` and alert mail.           |
| `WATCHTOWER_QUEUE_NAME`       | _(default queue)_           | Queue for `ProcessEventJob` and alert mail. Recommended: `watchtower`. |
| `WATCHTOWER_RATE_LIMIT_PER_MIN` | `300`                     | Events per minute per project, on every ingest path.             |
| `WATCHTOWER_RATE_LIMIT_PER_FINGERPRINT_PER_MIN` | `20`      | Events per minute per issue; the rest are counted, not stored.   |
| `WATCHTOWER_MAX_PAYLOAD_BYTES` | `1048576`                  | Largest envelope body accepted, as sent.                         |
| `WATCHTOWER_MAX_EVENT_BYTES`  | `200000`                    | Events are trimmed to this JSON size before being queued.        |
| `WATCHTOWER_MAX_STRING_BYTES` | `8192`                      | Cap on any single string in an event.                            |
| `WATCHTOWER_MAX_QUEUE_DEPTH`  | `5000`                      | Past this many waiting jobs, events are counted, not queued.     |
| `WATCHTOWER_REDACT_SQL_VALUES` | `true`                     | Strip row values from SQL error messages and query breadcrumbs.  |
| `WATCHTOWER_USER_CONTEXT`     | `true`                      | Attach the signed-in user to events (middleware on `web`/`api`). |
| `WATCHTOWER_USER_CONTEXT_GUARDS` | `auto`                   | `auto` tries every guard; or a comma list (`admin,web`) in priority order. |
| `WATCHTOWER_BEFORE_SEND`      | `true`                      | Drop framework noise (validation, auth, 404, CSRF) and scrub secrets before an event leaves the SDK. |
| `WATCHTOWER_RELAY_ENABLED`    | `true`                      | Register the browser route on boot.                              |
| `WATCHTOWER_RELAY_PATH`       | `/api/watchtower-relay`     | Browser route path (must live under `/api/`).                    |
| `WATCHTOWER_RELAY_TIMEOUT`    | `5`                         | Upstream request timeout (seconds), relay/dual.                  |
| `WATCHTOWER_RELAY_ASYNC`      | `false`                     | Forward envelopes through a queued job instead of inline.        |
| `WATCHTOWER_RELAY_QUEUE`      | _(default queue)_           | Queue name when async is on.                                     |
| `WATCHTOWER_VERIFY_SSL`       | `true`                      | Verify the upstream TLS certificate.                             |
| `WATCHTOWER_CONNECT_TIMEOUT`  | `3`                         | Guzzle connect timeout (seconds).                                |
| `WATCHTOWER_FORWARD_GZIP`     | `true`                      | gzip uncompressed envelopes (≥ 1 KB) before forwarding upstream. |

The ignored exceptions, the scrubbed keys and the user fields sent are arrays in `config/watchtower.php`.

## Troubleshooting

The package ships a Laravel Boost skill that covers install, verification, triage and the MCP server. Install it
into your Claude skills with `php artisan boost:install --skills`, or read it at
`vendor/phattarachai/watchtower-laravel/resources/boost/skills/watchtower-error-tracking/reference.md`.

## License

MIT.
