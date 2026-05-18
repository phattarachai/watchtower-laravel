# Watchtower Laravel

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

## Configuration

| Env key                       | Default                     | Purpose                                                          |
| ----------------------------- | --------------------------- | ---------------------------------------------------------------- |
| `WATCHTOWER_DSN`              | falls back to `SENTRY_LARAVEL_DSN` | Watchtower DSN: `http://{key}@{host}/{numeric-project-id}`. |
| `WATCHTOWER_RELAY_ENABLED`    | `true`                      | Register the relay route on boot.                                |
| `WATCHTOWER_RELAY_PATH`       | `/api/watchtower-relay`     | Relay endpoint path (must live under `/api/`).                   |
| `WATCHTOWER_RELAY_TIMEOUT`    | `5`                         | Upstream request timeout (seconds).                              |
| `WATCHTOWER_RELAY_ASYNC`      | `false`                     | Forward envelopes through a queued job instead of sync.          |
| `WATCHTOWER_RELAY_QUEUE`      | _(default queue)_           | Queue name when async is enabled.                                |
| `WATCHTOWER_VERIFY_SSL`       | `true`                      | Verify upstream TLS certificate.                                 |
| `WATCHTOWER_CONNECT_TIMEOUT`  | `3`                         | Guzzle connect timeout (seconds).                                |

## Browser side

The browser SDK posts envelopes to your own app at `/api/watchtower-relay`. The relay parses your configured DSN, forwards the request body verbatim to `{scheme}://{host_with_port}/api/watchtower-relay` on the Watchtower instance, and passes back the upstream status and rate-limit headers.

`watchtower:install` publishes a small helper to `resources/js/vendor/watchtower.js` that wraps `Sentry.init(...)` with the Watchtower-tuned defaults (same-origin tunnel, no PII, browser-extension `denyUrls`) and applies `<meta name="watchtower-user-*">` to `Sentry.setUser(...)`. Per Vite entry:

```js
import { initWatchtower } from './vendor/watchtower.js';

initWatchtower();
```

The Sentry config lives inside the published helper, so multiple entries don't duplicate it. Customize options (e.g. `ignoreErrors`) there once.

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
