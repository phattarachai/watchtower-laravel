---
name: watchtower-error-tracking
description: Use this skill when the user wants to wire up Watchtower (a self-hosted, Sentry-compatible exception tracker) into a new project, or connect Claude Code to Watchtower's MCP server for in-conversation issue triage. Covers Laravel backends end-to-end via the phattarachai/watchtower-laravel package (one command — DSN, exception handler patch, relay route, MCP registration, plus smart defaults for user-context middleware, BeforeSend noise filtering + secret scrubbing, and breadcrumbs), browser JavaScript via @sentry/browser with the tunnel option, verifying ingestion through Watchtower's REST API, and adding the team-scoped MCP server so Claude can query and triage issues directly. Triggers on mentions of Watchtower, sentry-laravel, @sentry/browser, SENTRY_LARAVEL_DSN, VITE_SENTRY_DSN, WATCHTOWER_DSN, SENTRY_SEND_DEFAULT_PII, WATCHTOWER_USER_CONTEXT, WATCHTOWER_BEFORE_SEND, "set up error tracking", "wire up Watchtower", "verify the exception was reported", "add Watchtower MCP", "claude mcp add watchtower", "triage Watchtower issues from Claude", "user tab empty in Watchtower", "scrub secrets in Sentry events", or "ignore validation exceptions".
version: 2026.05.18.1
---

# Watchtower error tracking — install

Watchtower is a self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs pointed at a Watchtower instance.

**Scope of this skill:** install + configure + verify, end-to-end. Laravel backends, browser JavaScript frontends, or fullstack apps combining both. See [`reference.md`](reference.md) for full setup, browser-only configurations, troubleshooting, and REST endpoint reference.

## One-command install (Laravel — covers most cases)

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install
```

The install command prompts for the DSN, writes env keys, patches `bootstrap/app.php` to wire the Sentry exception handler (required for Laravel 11+, easy to forget), publishes the relay route at `/api/watchtower-relay`, confirms `SENTRY_SEND_DEFAULT_PII`, writes Sentry breadcrumb env keys (SQL queries / cache / HTTP / Redis), publishes the browser user-context helper, and (when a Vite config is present) writes `VITE_SENTRY_DSN` + `VITE_SENTRY_TUNNEL`. See `reference.md` for the full step list, `--dry-run`, and `--dsn=…` flags.

After install, paste the printed `Sentry.init({ ..., tunnel: import.meta.env.VITE_SENTRY_TUNNEL })` snippet into your JS entry file (it imports and calls `applyWatchtowerUser()` from the published helper), drop the `<meta name="watchtower-user-*">` snippet into your root Blade layout's `<head>`, then `npm install --save @sentry/browser && npm run build`. See `reference.md` § "Browser-side init".

Verify with `php artisan watchtower:test`.

## Smart defaults (Laravel package)

`watchtower:install` ships a set of safe, reversible defaults so a fresh wire-up produces events that are immediately useful in Watchtower's `IssueDetail` UI — User tab populated, framework noise filtered, secrets scrubbed. Each behavior is opt-out via env or config.

| Default | What it does | Opt-out |
|---|---|---|
| `WatchtowerUserContext` middleware (auto-pushed onto `web` + `api` groups) | Walks configured guards in order (first authenticated wins), calls `Sentry::setUser({id, email, username, ip_address, …})`, sets the `auth.guard` tag. Default `fields = []` auto-discovers every column on the user model minus `$hidden` and a built-in deny-list (`password`, `remember_token`, `two_factor_*`, etc.). Result: Watchtower's User tab populates automatically; multi-guard apps tag which surface (web / api / admin) produced the exception. | `WATCHTOWER_USER_CONTEXT=false`, narrow `WATCHTOWER_USER_CONTEXT_GUARDS=web,admin`, or set explicit `watchtower.user_context.fields = ['id', 'email']` |
| `BeforeSend` filter (chained in front of any existing `before_send` from `config/sentry.php`) | Drops 9 framework exception classes before egress (`ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`, `TokenMismatchException`, `NotFoundHttpException`, `MethodNotAllowedHttpException`, `AccessDeniedHttpException`, `SuspiciousOperationException`) and scrubs request `data` / `headers` / `cookies` + event `extra` for known secret keys (case-insensitive) — `password`, `token`, `api_key`, `secret`, `authorization`, `cookie`, `credit_card`, `cvv`, etc. Credit-card-shape regex sweeps remaining string values. | `WATCHTOWER_BEFORE_SEND=false`, or edit `watchtower.before_send.{ignored_exceptions, scrub_keys}` |
| Breadcrumb env keys written by `watchtower:install` (only when absent) | `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=true`, `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED=false` (bindings can leak PII even after scrubbing), `SENTRY_BREADCRUMBS_CACHE_ENABLED=true`, `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED=true`, `SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED=true`. Result: events arrive with the last ~100 query/cache/HTTP/Redis ops attached — Watchtower's Breadcrumbs tab fills in. | Set any individual `SENTRY_BREADCRUMBS_*` key to `false` in `.env` |
| `SENTRY_SEND_DEFAULT_PII` install prompt | Defaults `yes` for non-local envs (so the request body + IP attach to events), `no` for `APP_ENV=local`. **Critical**: without this on, the Sentry SDK strips the request data + IP *before* `BeforeSend` runs — User tab + Request tab go empty. `BeforeSend` is the scrubbing safety net that makes it safe to leave on in production. | Answer `no` at the prompt, or flip the env key |
| Browser: `applyWatchtowerUser()` helper (`resources/js/vendor/watchtower-user-context.js`) | Reads `<meta name="watchtower-user-{id,email,name}">` from the document and calls `Sentry.setUser(...)` after `Sentry.init`. The install command publishes the helper file and prints the matching meta-tag snippet for the Blade layout. | Don't paste the meta tags, or don't call the helper |

Config reference (`config/watchtower.php` → `user_context` and `before_send` sections) and the full env-key table live in `reference.md` § "Smart defaults".

## DSN must be numeric

Watchtower DSNs end in the project's **numeric id**, not its slug:

```
https://{public_key}@watchtower.phattarachai.app/42      ✅
https://{public_key}@watchtower.phattarachai.app/my-app  ❌
```

Stock Sentry SDKs silently reject non-numeric project segments at parse time — the SDK initializes but no events ever leave the client. The settings page renders the numeric form; copy verbatim. See `reference.md` § "DSN format" for the underlying cause.

## One Watchtower project per client app

Default to a single Watchtower project per client app — the same DSN authenticates the Laravel backend (`SENTRY_LARAVEL_DSN`) and the browser frontend (`VITE_SENTRY_DSN`). Backend exceptions and JS errors land in the same inbox; project name in the breadcrumb tells them apart. `watchtower:install` writes both env keys to that DSN automatically.

The trade-off: a frontend DSN is visible in the JS bundle, so a leaked key can be used to forge events. Watchtower's per-project rate limit caps the blast radius; if that's not enough for a given app (regulated environment, public-facing target, very noisy frontend), split into two projects manually — set `VITE_SENTRY_DSN` to a different project's DSN in `.env` after `watchtower:install` runs. Not the default; opt in when you have a reason.

## Verifying an exception via REST

After triggering an exception, confirm Watchtower received it without opening the web UI. The DSN's public key doubles as a Bearer token for read + triage.

```bash
EVENT_ID=$(php artisan tinker --execute 'echo \Sentry\captureMessage("watchtower-probe-".now())->__toString();')
PUBLIC_KEY=$(grep -oE '://[a-z0-9]+@' .env | head -1 | tr -d ':/@')   # extract from SENTRY_LARAVEL_DSN

curl -fsSL -H "Authorization: Bearer $PUBLIC_KEY" \
  https://watchtower.phattarachai.app/api/v1/events/$EVENT_ID
```

200 → ingested. 404 → not received yet (the queue is async; retry after a few seconds). See `reference.md` § "Querying via REST" for the full endpoint list (issues, events, triage actions).

## Using the Watchtower MCP server (recommended when Claude is connected)

Watchtower exposes an MCP server at `/mcp` so Claude Code can query and triage issues directly — no curl, no copying event_ids between tabs. **One server per Watchtower team, not per project**: a team with a Laravel backend (`acme`) and a JS frontend (`acme-web`) shares one MCP setup, and Claude sees both projects from the single connection.

### Add the server to your project

`php artisan watchtower:install` registers the MCP server with Claude Code automatically when the `claude` CLI is on PATH. To register manually (e.g. after `--no-mcp` or on a machine without Claude at install time):

```bash
claude mcp add watchtower https://watchtower.phattarachai.app/mcp \
  --header "Authorization: Bearer <PUBLIC_KEY>"
```

`<PUBLIC_KEY>` is any project's DSN public_key in the team — the segment between `https://` and `@` in `SENTRY_LARAVEL_DSN`. Either project's key authenticates Claude against every project in the team; pick whichever you have to hand.

### Tools at a glance

10 tools, all team-scoped:

- **Discover** — `get_team`, `get_stats` (volumes + top issues + status mix).
- **Read** — `list_issues`, `get_issue` (summary + `latest_event_id` for the common drill-down), `list_events`, `get_event` (full payload — stacktrace, breadcrumbs, request, contexts — used both for verification after capture AND as the debugging entry point).
- **Triage** — `resolve_issue`, `ignore_issue`, `unresolve_issue`, `snooze_issue`.

The "fix the latest occurrence of issue N" flow is two calls: `get_issue(N)` → grab `latest_event_id` from the response → `get_event(<that uuid>)` to see the stack and breadcrumbs.

Full arg + return reference: `reference.md` § "Querying via MCP".

### MCP vs REST

- **MCP** — first choice when Claude is in the conversation. The model picks the right tool from descriptions and gets structured responses.
- **REST** (`curl /api/v1/...`) — better fit for CI scripts, post-deploy smoke tests, and any non-agent context. See `reference.md` § "Querying via REST".
