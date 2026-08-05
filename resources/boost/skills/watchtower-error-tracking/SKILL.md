---
name: watchtower-error-tracking
description: "Wire up Watchtower (a self-hosted, Sentry-compatible exception tracker) into a Laravel or browser app, and connect Claude Code to its MCP server for in-conversation issue triage. Triggers on \"Watchtower\", \"set up error tracking\", \"verify the exception was reported\", WATCHTOWER_DSN, SENTRY_LARAVEL_DSN, or VITE_SENTRY_DSN."
version: 2026.08.05.1
---

# Watchtower error tracking

Watchtower is a self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs pointed at a Watchtower instance.

**Scope of this skill:** everything past the one-paragraph blurb Boost auto-injects into the project's CLAUDE.md (`resources/boost/guidelines/core.md`) — what the package wires up, install, triage, verification, troubleshooting. For full env-key tables and REST endpoint shapes see [`reference.md`](reference.md).

## What the package wires up

- **Backend reporting** — `sentry/sentry-laravel` pointed at the Watchtower instance via `SENTRY_LARAVEL_DSN`, with `Integration::handles($exceptions)` wired into `bootstrap/app.php`.
- **Browser reporting** — `@sentry/browser` pointed at `VITE_SENTRY_DSN` and tunnelled through the same-origin `/api/watchtower-relay` route, so ad blockers don't drop envelopes.
- **User context** — the `WatchtowerUserContext` middleware plus the `@watchtowerUser` Blade directive, so events arrive with the signed-in user attached.
- **Noise filtering + scrubbing** — a `before_send` pipeline that drops framework exceptions (validation, auth, 404, CSRF, …) and strips secrets from request data, headers, cookies, and extra.
- **MCP server** — a project-scoped `watchtower` entry in `.mcp.json` exposing `mcp__watchtower__*` tools for issue triage from inside Claude Code.

Config lives in `config/watchtower.php`; every default above is opt-out via env (see [Smart defaults](#smart-defaults-laravel-package)). `php artisan watchtower:test` verifies backend, relay, and frontend wiring end to end.

## Provision the project (headless)

If you don't yet have a DSN, you can create the Watchtower project and mint one without
leaving the terminal — no clicking through the UI. This needs a **Personal Access Token**
(PAT) stored once on the dev machine at `~/.watchtower/token`.

```bash
PAT=$(cat ~/.watchtower/token 2>/dev/null)
if [ -n "$PAT" ]; then
  DSN=$(curl -fsS -X POST https://watchtower.phattarachai.app/api/v1/projects \
    -H "Authorization: Bearer $PAT" -H 'Content-Type: application/json' \
    -d '{"name":"<App Name>","slug":"<slug>","platform":"php-laravel"}' \
    | jq -r '.data.dsn')
  composer require phattarachai/watchtower-laravel
  php artisan watchtower:install --dsn="$DSN"
  php artisan watchtower:test
fi
```

The endpoint is **idempotent**: re-running with the same slug returns the existing DSN
(`.data.created` is `false`), so it's safe to retry. Optional `team_slug` targets a specific
team (default: the PAT user's first team). Resolve / verify a slug or team with
`GET /api/v1/me` and `GET /api/v1/me/projects`.

**One-time bootstrap** — mint a token at `/me/access-tokens`, then:

```bash
echo '<token>' > ~/.watchtower/token && chmod 600 ~/.watchtower/token
```

**No PAT?** Fall back to the manual onboarding flow below — create the project in the
Watchtower UI, copy its DSN, and run `watchtower:install` (it prompts for the DSN).

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

`watchtower:install` registers it automatically when the `claude` CLI is on PATH, using `--scope project` so the config lands in a committed `.mcp.json` at the repo root — teammates who `composer install` get the MCP on pull (after approving it once) without re-running the installer. Manual registration:

```bash
claude mcp add --transport http --scope project watchtower https://watchtower.phattarachai.app/mcp \
  --header "Authorization: Bearer <PUBLIC_KEY>"
```

`<PUBLIC_KEY>` is the project's DSN public_key — the segment between `https://` and `@` in `SENTRY_LARAVEL_DSN`. It's already shipped to browsers via `VITE_SENTRY_DSN`, so committing it in `.mcp.json` is safe. If you actually split backend and browser into two Watchtower projects (rare — see `reference.md` § "When to split into two projects"), register one MCP server per project with distinct names (e.g. `watchtower-backend`, `watchtower-frontend`).

## MCP triage (when `mcp__watchtower__*` tools are connected)

- `mcp__watchtower__list_issues` / `mcp__watchtower__list_events` — browse, filter by environment / level / release / `since`.
- `mcp__watchtower__get_issue` — drill into one issue group. Use right after `list_issues` and grab `latest_event_id` from the response for the common "fix the latest occurrence" flow.
- `mcp__watchtower__get_event` — fetch a full event payload (stacktrace, breadcrumbs, request, contexts). The debugging entry point: feed the `event_id` from `get_issue.latest_event_id` (or from a verification capture) and read the stack to locate the bug.
- `mcp__watchtower__resolve_issue` / `ignore_issue` / `unresolve_issue` / `snooze_issue` — triage actions. Use after the user has confirmed a fix or noise classification, not unilaterally.
- `mcp__watchtower__get_stats` — volumes, top issues, status mix; useful for "what's noisy right now?" questions.

Full arg + return reference, plus the end-to-end verify and debug flows: `reference.md` § "Querying via MCP".
