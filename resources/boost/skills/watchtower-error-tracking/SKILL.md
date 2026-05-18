---
name: watchtower-error-tracking
description: Use this skill when the user wants to wire up Watchtower (a self-hosted, Sentry-compatible exception tracker) into a new project, or connect Claude Code to Watchtower's MCP server for in-conversation issue triage. Covers Laravel backends end-to-end via the phattarachai/watchtower-laravel package (one command — DSN, exception handler patch, relay route, MCP registration, plus smart defaults for user-context middleware, BeforeSend noise filtering + secret scrubbing, and breadcrumbs), browser JavaScript via @sentry/browser with the tunnel option, verifying ingestion through Watchtower's REST API, and adding the project-scoped MCP server so Claude can query and triage issues directly. Triggers on mentions of Watchtower, sentry-laravel, @sentry/browser, SENTRY_LARAVEL_DSN, VITE_SENTRY_DSN, WATCHTOWER_DSN, SENTRY_SEND_DEFAULT_PII, WATCHTOWER_USER_CONTEXT, WATCHTOWER_BEFORE_SEND, "set up error tracking", "wire up Watchtower", "verify the exception was reported", "add Watchtower MCP", "claude mcp add watchtower", "triage Watchtower issues from Claude", "user tab empty in Watchtower", "scrub secrets in Sentry events", or "ignore validation exceptions".
version: 2026.05.18.3
---

# Watchtower error tracking

Watchtower is a self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs pointed at a Watchtower instance.

**Scope of this skill:** triage / verify / debug workflows once the package is installed, plus the install entry point. The package ships a short usage guideline that Boost auto-injects into the project's CLAUDE.md (`resources/boost/guidelines/core.md`) covering the day-to-day MCP triage patterns. This file is the deeper reference. For full env-key tables and REST endpoint shapes see [`reference.md`](reference.md).

## Install in 3 commands

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install
php artisan watchtower:test
```

`watchtower:install` is idempotent. It:

1. Validates the DSN (project segment must be **numeric** — stock Sentry SDKs reject non-numeric project ids silently).
2. Writes env keys, patches `bootstrap/app.php` for the Sentry exception handler, publishes the `/api/watchtower-relay` route + browser user-context helper.
3. **Scans `vite.config.js`** for `laravel({ input: [...] })` entries and **globs `resources/views/{components/,}layouts/`** for layouts with a `<head>`, then prints a per-entry / per-layout placement list — concrete files, not "paste it somewhere".
4. Detects Filament panel providers (`app/Providers/Filament/*PanelProvider.php`) and prints the `renderHook('panels::head.end', …)` snippet for each, because Filament admin pages don't use the regular Blade layout.
5. Registers the Watchtower MCP server with Claude Code if `claude` is on PATH.

Add `--patch-js` to inject the Sentry init block at the top of every detected Vite entry, behind a `// watchtower:sentry-init` sentinel. Add `--patch-views` to inject the meta-tag block inside every detected layout's `</head>`, behind a `{{-- watchtower:user-meta --}}` sentinel. Both are idempotent — reruns are no-ops once the markers are in place. Skip the flags to keep the install advisory and paste manually.

`watchtower:test` runs the backend probe + relay probe AND verifies the frontend wiring landed: missing sentinels, an unexpanded `\${APP_ENV}` in `.env`, layouts without the meta block. Treat any warning as a real problem — the most common "events arrive but the User tab is empty" cause is a skipped paste.

## Smart defaults (Laravel package)

`watchtower:install` ships a set of safe, reversible defaults so a fresh wire-up produces events that are immediately useful in Watchtower's `IssueDetail` UI — User tab populated, framework noise filtered, secrets scrubbed. Each behavior is opt-out via env or config.

| Default | What it does | Opt-out |
|---|---|---|
| `WatchtowerUserContext` middleware (auto-pushed onto `web` + `api` groups) | Walks configured guards in order (first authenticated wins), calls `Sentry::setUser({id, email, username, ip_address, …})`, sets the `auth.guard` tag. Default `fields = []` auto-discovers every column on the user model minus `$hidden` and a built-in deny-list (`password`, `remember_token`, `two_factor_*`, etc.). Result: Watchtower's User tab populates automatically; multi-guard apps tag which surface (web / api / admin) produced the exception. | `WATCHTOWER_USER_CONTEXT=false`, narrow `WATCHTOWER_USER_CONTEXT_GUARDS=web,admin`, or set explicit `watchtower.user_context.fields = ['id', 'email']` |
| `BeforeSend` filter (chained in front of any existing `before_send` from `config/sentry.php`) | Drops 9 framework exception classes before egress (`ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`, `TokenMismatchException`, `NotFoundHttpException`, `MethodNotAllowedHttpException`, `AccessDeniedHttpException`, `SuspiciousOperationException`) and scrubs request `data` / `headers` / `cookies` + event `extra` for known secret keys (case-insensitive) — `password`, `token`, `api_key`, `secret`, `authorization`, `cookie`, `credit_card`, `cvv`, etc. Credit-card-shape regex sweeps remaining string values. | `WATCHTOWER_BEFORE_SEND=false`, or edit `watchtower.before_send.{ignored_exceptions, scrub_keys}` |
| Breadcrumb env keys written by `watchtower:install` (only when absent) | `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=true`, `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED=false` (bindings can leak PII even after scrubbing), `SENTRY_BREADCRUMBS_CACHE_ENABLED=true`, `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED=true`, `SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED=true`. Result: events arrive with the last ~100 query/cache/HTTP/Redis ops attached — Watchtower's Breadcrumbs tab fills in. | Set any individual `SENTRY_BREADCRUMBS_*` key to `false` in `.env` |
| `SENTRY_SEND_DEFAULT_PII` install prompt | **Opt-in**: defaults to `no` across every env (regulated industries and casual installs both get the conservative default). Without it, the Sentry SDK strips the request data + IP *before* `BeforeSend` runs — User tab + Request tab stay empty. Flip to `yes` only after confirming `BeforeSend`'s scrub coverage is sufficient for your app's data; then `BeforeSend` becomes the scrubbing safety net that makes it safe to leave on. | Answer `yes` at the prompt, or set `SENTRY_SEND_DEFAULT_PII=true` in `.env` after install |
| Browser: `initWatchtower()` helper (`resources/js/vendor/watchtower.js`) | Single-call entry-point that runs `Sentry.init(...)` with Watchtower-tuned defaults (same-origin tunnel, `denyUrls` for browser extensions, no PII) and then reads `<meta name="watchtower-user-{id,email,name}">` from the document to call `Sentry.setUser(...)`. Per-entry footprint is `import { initWatchtower } from './vendor/watchtower.js'; initWatchtower();` — the Sentry config lives inside the helper, so multiple entries don't duplicate it. The install command publishes the helper; `--patch-js` injects the import into detected Vite entries; `--patch-views` injects the `@watchtowerUser` directive into detected layouts. | Don't paste the directive, or don't call the helper |
| Blade: `@watchtowerUser` directive | Compiles to the three `<meta name="watchtower-user-{id,email,name}">` tags read by the browser helper. Registered automatically by the package. The source view lives at `vendor/phattarachai/watchtower-laravel/resources/views/user-meta.blade.php`; publish with `php artisan vendor:publish --tag=watchtower-views` to override at `resources/views/vendor/watchtower/user-meta.blade.php`. For Filament admin panels, register a `PanelsRenderHook::HEAD_END` hook that returns `Blade::render('@watchtowerUser')`. | Don't add `@watchtowerUser` to the layout / panel hook |

Config reference (`config/watchtower.php` → `user_context` and `before_send` sections) and the full env-key table live in `reference.md` § "Smart defaults".

## Verifying an exception via REST

When MCP isn't available, the DSN's public key doubles as a Bearer token for read + triage against the REST API:

```bash
EVENT_ID=$(php artisan tinker --execute 'echo \Sentry\captureMessage("watchtower-probe-".now())->__toString();')
PUBLIC_KEY=$(grep -oE '://[a-z0-9]+@' .env | head -1 | tr -d ':/@')   # extract from SENTRY_LARAVEL_DSN

curl -fsSL -H "Authorization: Bearer $PUBLIC_KEY" \
  https://watchtower.phattarachai.app/api/v1/events/$EVENT_ID
```

200 → ingested. 404 → not received yet (the queue is async; retry after a few seconds). Full endpoint list in `reference.md` § "Querying via REST".

## MCP server

Watchtower exposes an MCP server at `/mcp` so Claude can query and triage issues directly. Each registration is scoped to one Watchtower project — same as the project-scoped REST endpoints. The package's default of one Watchtower project per client app means a single registration covers backend exceptions and browser exceptions in the same inbox.

`watchtower:install` registers it automatically when the `claude` CLI is on PATH. Manual registration:

```bash
claude mcp add watchtower https://watchtower.phattarachai.app/mcp \
  --header "Authorization: Bearer <PUBLIC_KEY>"
```

`<PUBLIC_KEY>` is the project's DSN public_key — the segment between `https://` and `@` in `SENTRY_LARAVEL_DSN`. If you actually split backend and browser into two Watchtower projects (rare — see `reference.md` § "When to split into two projects"), register one MCP server per project with distinct names (e.g. `watchtower-backend`, `watchtower-frontend`).

Day-to-day tool selection lives in the auto-injected CLAUDE.md guideline (`resources/boost/guidelines/core.md`). Full arg + return reference: `reference.md` § "Querying via MCP".
