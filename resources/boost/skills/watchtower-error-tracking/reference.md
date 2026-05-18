---
name: watchtower-error-tracking-reference
description: End-to-end install reference for wiring Watchtower error tracking into a new project. Covers Laravel backends (via the phattarachai/watchtower-laravel package, one command), browser JavaScript frontends (via @sentry/browser with the tunnel option), the verify-via-REST flow, the team-scoped MCP server for in-conversation triage from Claude Code, and troubleshooting. Read this when SKILL.md directs you here, or when the user wants to install, configure, verify, triage, or troubleshoot Watchtower error tracking.
version: 2026.05.18
---

# Watchtower install — reference

Companion to [`SKILL.md`](SKILL.md). This is the canonical install guide. Covers Laravel + browser JavaScript end-to-end.

## What Watchtower is

Self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs (`sentry/sentry-laravel` on the backend, `@sentry/browser` in the browser) pointed at your Watchtower instance. **Exception-only** — Watchtower drops trace / transaction (performance) items at ingest, so do not enable performance monitoring on the client side.

## Pick the right Watchtower project for this codebase

One Watchtower project per runtime. Don't share a project across PHP + JS:

| Reason | Why |
|---|---|
| Public-key exposure | A browser DSN is visible in the JS bundle. If the same DSN authenticates server events, a leaked frontend key lets anyone forge backend exceptions. |
| Fingerprinting noise | PHP stack frames and minified JS frames don't share shape. One project = one inbox where backend and frontend errors collide. |
| Independent alerting | Frontend errors are noisier (extension noise, third-party scripts). Different cooldowns, different recipients, different rate limits. |
| Triage at-a-glance | Project name in the inbox answers "backend or frontend?" without opening the event. |

Typical fullstack split:

- `acme` (platform: `php-laravel`) — Laravel backend, used by `sentry/sentry-laravel`.
- `acme-web` (platform: `javascript`) — browser JS, used by `@sentry/browser`.

Each has its own DSN. Create them at `https://watchtower.phattarachai.app/projects/create`.

## DSN format — must be numeric

```
https://{public_key}@{host}/{numeric_project_id}
```

The project segment **MUST** be the project's numeric id. Stock Sentry SDKs validate at `Dsn::parse()` time and silently no-op the transport if the segment isn't `\d+`. The slug works in cURL pokes but breaks every real SDK. The settings page renders the numeric form by default — copy it verbatim.

## Laravel install (one command)

For any Laravel 12 / 13 project (with or without a Vite frontend), use the install package.

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install
```

The package is published at <https://packagist.org/packages/phattarachai/watchtower-laravel> (GitHub source: <https://github.com/phattarachai/watchtower-laravel>).

`watchtower:install` does five things:

1. **Resolves the DSN.** From `--dsn=…`, then `WATCHTOWER_DSN`, then `SENTRY_LARAVEL_DSN`, otherwise prompts. Validates the DSN format (numeric project id required).
2. **Writes env keys.** Sets `WATCHTOWER_DSN` and `SENTRY_LARAVEL_DSN` in `.env`. Empty in `.env.example`. For `APP_ENV=local`, prompts whether to use `null` (recommended — don't flood Watchtower from dev).
3. **Patches `bootstrap/app.php`.** Adds `use Sentry\Laravel\Integration;` and inserts `Integration::handles($exceptions);` inside `withExceptions(...)`. **Critical** — without this, unhandled exceptions in HTTP requests, jobs, and commands never reach Watchtower. If the package can't recognize the file's shape, it prints the diff and bails — apply it by hand.
4. **Publishes `config/watchtower.php`.** Knobs: relay path, timeout, async forwarding flag, queue name.
5. **Detects Vite.** If `vite.config.{js,ts}` exists, writes `VITE_SENTRY_DSN`, `VITE_SENTRY_TUNNEL=/api/watchtower-relay`, and `VITE_SENTRY_ENVIRONMENT="${APP_ENV}"` to `.env` and `.env.example`. Prints the `Sentry.init(...)` snippet to paste into the entry JS file (see [Browser-side init](#browser-side-init) below).

Flags:
- `--dsn=…` — skip the prompt.
- `--dry-run` — print intended changes (env diff + `bootstrap/app.php` unified diff) without writing.

After install: `php artisan watchtower:test` sends a synthetic exception through both the Laravel SDK path and the local relay path.

### How the relay works

The package registers a route at `POST /api/watchtower-relay`. Browser SDKs post envelopes to this same-origin path with `Sentry.init({ tunnel: '/api/watchtower-relay' })`. The relay reads the body and forwards it to `{WATCHTOWER_DSN host}/api/watchtower-relay`. Two reasons:

1. **Ad blockers.** uBlock, AdGuard, and Brave Shields drop URLs containing `sentry_key=` (the default Sentry SDK URL format). A relay path under your own domain has neither `sentry_key=` nor a recognizable Sentry hostname, so filter lists don't match.
2. **CORS.** Same-origin means no CORS preflight, no `OPTIONS` requests.

#### Sync vs async forwarding

Default: sync (Guzzle POST to upstream within the request lifecycle). The browser SDK uses `keepalive: true` so it doesn't block the page, but the relay still spends a Laravel worker thread forwarding bytes. Latency typically 50–200ms.

Opt in to async by setting `WATCHTOWER_RELAY_ASYNC=true` — the relay dispatches a `ForwardEnvelope` job and returns 202 immediately. Requires a real queue (Redis / Horizon / database driver). Set `WATCHTOWER_RELAY_QUEUE` if you want a non-default queue name.

### Configuration reference

`config/watchtower.php` exposes all knobs through env:

| Env key | Default | Purpose |
|---|---|---|
| `WATCHTOWER_DSN` | (required) | Upstream DSN. Host is derived from this. |
| `SENTRY_LARAVEL_DSN` | (alias) | The package sets both to the same value for SDK compatibility. |
| `WATCHTOWER_RELAY_ENABLED` | `true` | Set to `false` to skip route registration entirely. |
| `WATCHTOWER_RELAY_PATH` | `/api/watchtower-relay` | Path the browser SDK posts to. Change only if it collides with an existing route. |
| `WATCHTOWER_RELAY_ASYNC` | `false` | Forward via queue instead of inline. |
| `WATCHTOWER_RELAY_QUEUE` | `null` | Queue name when async. |
| `WATCHTOWER_RELAY_TIMEOUT` | `5` | Guzzle request timeout (seconds). |
| `WATCHTOWER_CONNECT_TIMEOUT` | `3` | Guzzle connect timeout (seconds, float). |
| `WATCHTOWER_VERIFY_SSL` | `true` | Disable for self-signed dev Watchtower instances only. |

### Recommended `.env` per environment

| Environment | `SENTRY_LARAVEL_DSN` | `VITE_SENTRY_DSN` | `WATCHTOWER_RELAY_ASYNC` |
|---|---|---|---|
| Production | full DSN | full DSN | `true` (if Horizon present) |
| Staging | full DSN | full DSN | `true` |
| Local dev | `null` | full DSN (for browser dev) or `null` | `false` |

**Do not set `SENTRY_TRACES_SAMPLE_RATE`.** Watchtower drops trace items.

## Browser-side init

The install command writes the env keys but does NOT modify your entry JS file — that one-time paste is on you. In `resources/js/app.js` (or wherever your bundler entry is), add at the top:

```javascript
import * as Sentry from '@sentry/browser';

if (import.meta.env.VITE_SENTRY_DSN) {
    Sentry.init({
        dsn: import.meta.env.VITE_SENTRY_DSN,
        tunnel: import.meta.env.VITE_SENTRY_TUNNEL,  // /api/watchtower-relay
        environment: import.meta.env.VITE_SENTRY_ENVIRONMENT ?? 'production',
        tracesSampleRate: 0,
        sendDefaultPii: false,
    });
}
```

Then:

```bash
npm install --save @sentry/browser
npm run build
```

The `tunnel` option is non-negotiable for production deployments. Without it, ~10–30% of users with ad blockers will silently fail to report errors.

### Verify the browser side

Open the app in a browser, then in DevTools console:

```javascript
setTimeout(() => { throw new Error('watchtower probe ' + Date.now()) }, 0)
```

Async throw (not a sync `throw` in the console — the console swallows those). In DevTools → Network, filter for `watchtower-relay` — you should see a `POST /api/watchtower-relay` returning 200.

## Browser-only setup (no Laravel host)

If the project is a pure JavaScript app (Next.js, Astro, Vite SPA, static site with a Node server, etc.) with no Laravel runtime, you have two sub-cases:

### Same-origin Watchtower

If you control DNS and want the JS app served from the *same domain* as Watchtower (e.g. `app.example.com` for the app, `errors.example.com` for Watchtower, both behind a reverse proxy that routes `/api/watchtower-relay` to Watchtower): set the SDK tunnel to point directly at Watchtower's own `/api/watchtower-relay` endpoint. No app-side proxy needed.

```javascript
Sentry.init({
    dsn: import.meta.env.VITE_SENTRY_DSN,
    tunnel: 'https://errors.example.com/api/watchtower-relay',  // direct
    // ...
});
```

### Cross-origin (your own server forwards)

Most cases. You need a tiny relay endpoint in whatever runtime hosts the JS. The job: receive POST `/api/watchtower-relay`, forward the raw body and `Content-Type` / `Content-Encoding` headers to `https://watchtower.phattarachai.app/api/watchtower-relay`, return the upstream response verbatim. ~20 lines in any runtime. Express example:

```javascript
app.post('/api/watchtower-relay', express.raw({ type: '*/*', limit: '1mb' }), async (req, res) => {
    const upstream = await fetch('https://watchtower.phattarachai.app/api/watchtower-relay', {
        method: 'POST',
        headers: {
            'Content-Type': req.get('Content-Type') ?? 'application/octet-stream',
            ...(req.get('Content-Encoding') ? { 'Content-Encoding': req.get('Content-Encoding') } : {}),
        },
        body: req.body,
    });
    res.status(upstream.status).set('Content-Type', upstream.headers.get('Content-Type') ?? 'application/json');
    upstream.body.pipe(res);
});
```

## Manual install (Laravel — fallback when the package isn't available)

Use only when `composer require phattarachai/watchtower-laravel` isn't an option (locked composer.json, air-gapped environment, Laravel 10 or older).

### 1. Install the SDK

```bash
composer require sentry/sentry-laravel
```

### 2. Wire the exception handler (Laravel 11+) — REQUIRED

Edit `bootstrap/app.php`:

```php
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })
    ->create();
```

Without this, real unhandled exceptions are silently dropped. `php artisan sentry:test` still works (it sends a synthetic event directly), so don't take a successful `sentry:test` as proof of wiring.

For Laravel 10, register in `app/Exceptions/Handler.php` instead — see the sentry-laravel docs.

### 3. Publish config + set the DSN

```bash
php artisan sentry:publish --dsn=https://YOUR_PUBLIC_KEY@watchtower.phattarachai.app/NUMERIC_PROJECT_ID
```

### 4. (Optional) Browser tunnel

If you also serve browser JS from this app and want ad-blocker-safe reporting, you'll need a relay route. Either install the package (which provides it for free) or copy this manual controller:

```php
// routes/api.php
Route::post('/watchtower-relay', function (\Illuminate\Http\Request $request) {
    $upstream = (string) env('WATCHTOWER_RELAY_UPSTREAM', 'https://watchtower.phattarachai.app/api/watchtower-relay');
    $response = \Illuminate\Support\Facades\Http::withHeaders(array_filter([
        'Content-Type' => $request->header('Content-Type'),
        'Content-Encoding' => $request->header('Content-Encoding'),
    ]))->withBody($request->getContent(), $request->header('Content-Type') ?? 'application/octet-stream')
       ->post($upstream);

    return response($response->body(), $response->status())
        ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
})->name('watchtower.relay.manual');
```

### 5. (Recommended) PHP setting for richer stack traces

`zend.exception_ignore_args = Off` so Sentry can capture argument values in frames.

## Querying via REST — verify + triage

Watchtower exposes `/api/v1/...` endpoints for verifying ingestion and triaging issues without opening the web UI. The DSN's `public_key` is the bearer token — no separate API token needed.

### Auth

```
Authorization: Bearer {public_key from your DSN}
```

Or `?api_key={public_key}` for quick curl pokes. The token is project-scoped: every endpoint operates against the project that owns the token. Revoking / regenerating the DSN invalidates the bearer too.

Rate limit: 60 req/min per token.
Pagination: `?page=N`, 25/page.
Errors: standard Laravel `{"message": "...", "errors": {...}}` shape; 401 unauth, 404 not-in-scope, 422 validation, 429 rate-limited.

### Endpoints

| Method | URL | Purpose |
|---|---|---|
| GET | `/api/v1/project` | Show this token's project (id, slug, name, platform). |
| GET | `/api/v1/issues` | List issue groups. Filters: `environment`, `level`, `status`, `fingerprint`, `since` (`24h`/`7d` or ISO), `q`. |
| GET | `/api/v1/issues/{id}` | Show one issue group. |
| PATCH | `/api/v1/issues/{id}` | Triage. Body: `{"status":"resolved"}` etc. Snooze requires `snooze_duration`. |
| GET | `/api/v1/events` | List recent events. Filters: `environment`, `release`, `level`, `since`, `group_id`. |
| GET | `/api/v1/events/{event_id}` | Verification core — lookup by Sentry envelope event_id (UUID). |

### Examples

Verify a specific exception arrived:

```bash
curl -fsSL -H "Authorization: Bearer $PUBLIC_KEY" \
  https://watchtower.phattarachai.app/api/v1/events/$EVENT_ID
```

List unresolved errors in production from the last 24h:

```bash
curl -fsSL -H "Authorization: Bearer $PUBLIC_KEY" \
  "https://watchtower.phattarachai.app/api/v1/issues?status=unresolved&environment=production&level=error&since=24h"
```

Resolve an issue:

```bash
curl -fsSL -X PATCH -H "Authorization: Bearer $PUBLIC_KEY" \
  -H "Content-Type: application/json" \
  -d '{"status":"resolved"}' \
  https://watchtower.phattarachai.app/api/v1/issues/39
```

Snooze for 24h:

```bash
curl -fsSL -X PATCH -H "Authorization: Bearer $PUBLIC_KEY" \
  -H "Content-Type: application/json" \
  -d '{"status":"snoozed","snooze_duration":"24h"}' \
  https://watchtower.phattarachai.app/api/v1/issues/39
```

Valid `snooze_duration` values: `1h`, `24h`, `7d`, `30d`, `until_event`.

> **Privilege scope.** Anyone with the DSN can read **and** triage issues. Per-user API tokens are deferred to a later milestone.

## Querying via MCP

Watchtower exposes a Model Context Protocol server at `/mcp` for Claude Code (and any other MCP client). Same auth model as REST — the DSN public_key is the bearer token — but **team-scoped**: a single MCP server gives Claude access to every project in the Watchtower team that owns the DSN. Set it up once per team, not once per project.

### Add the server

`php artisan watchtower:install` registers the MCP server with Claude Code automatically when the `claude` CLI is on PATH (use `--no-mcp` to skip). To register manually after the fact:

```bash
claude mcp add watchtower https://watchtower.phattarachai.app/mcp \
  --header "Authorization: Bearer <PUBLIC_KEY>"
```

Extract `<PUBLIC_KEY>` from any team-member project's `.env`:

```bash
grep -oE '://[a-z0-9]+@' .env | head -1 | tr -d ':/@'
```

For a team with both a Laravel backend and a JS frontend, either project's DSN key authenticates Claude against both projects.

### Tool reference

| Tool | Args | Returns |
|---|---|---|
| `get_team` | — | Team summary + projects array (`id`, `slug`, `name`, `platform`, `retention_days`, `team`) |
| `get_stats` | `project?`, `window?` (`24h`/`7d`/`30d`, default `7d`) | Event volume + per-project + per-level breakdown + top-5 issues in window + status_mix |
| `list_issues` | `project?`, `status?`, `environment?`, `level?`, `q?`, `since?`, `page?` | Paginated issue groups across the team, newest-seen first |
| `get_issue` | `issue_id` | One issue group + permalink to the Watchtower UI |
| `list_events` | `project?`, `environment?`, `release?`, `level?`, `group_id?`, `since?`, `page?` | Paginated events across the team, newest first |
| `get_event` | `event_id` (UUID) | One event — the **verification core** |
| `resolve_issue` | `issue_id` | Marks resolved; re-opens automatically on regression in a higher release |
| `ignore_issue` | `issue_id` | Marks ignored; does NOT re-open on new events |
| `unresolve_issue` | `issue_id` | Re-opens a resolved or ignored issue |
| `snooze_issue` | `issue_id`, `duration` (`1h`/`24h`/`7d`/`30d`/`until_event`) | Snoozes; `until_event` un-snoozes on the next event |

### Verifying an exception via MCP

After capturing an exception in the client app, ask Claude:

> "Use Watchtower to verify event_id `<uuid>` arrived."

Claude calls `get_event`. Success → ingested. An error response saying *"Event not found in this team. The ingest pipeline is async — retry after a few seconds…"* → the queue hasn't drained yet, try again in a moment.

### Privilege scope

DSN holder gets read **and** mutate access across the whole team. Per-user / per-team-member tokens are not implemented yet — same boundary as the REST endpoints.

## Troubleshooting

| Symptom | First thing to check | Fix |
|---|---|---|
| Laravel: `php artisan sentry:test` works, but real exceptions never appear | `Integration::handles($exceptions)` not wired in `bootstrap/app.php` | Re-run `php artisan watchtower:install`, or apply the diff manually |
| Laravel: `php artisan sentry:test` returns 404 | DSN project segment is non-numeric (slug instead of id) | Re-copy the DSN from project settings — it should end in a number |
| Laravel: `php artisan sentry:test` returns 401 | DSN public key stale | Re-copy DSN |
| JS: no network request at all | `import.meta.env.VITE_SENTRY_DSN` was empty at *build* time | Set the env, then `npm run build` |
| JS: request blocked / "Failed to fetch" / status 0 / `503` and no nginx hit | Ad blocker matching `sentry_key=` | The `tunnel` option fixes this — verify it's set |
| JS: `window.Sentry.getClient().getDsn()` returns `null` | DSN project segment non-numeric | Use numeric id in DSN |
| Tunnel: `502 upstream_unreachable` | Client app can't reach upstream Watchtower | DNS / firewall — check `WATCHTOWER_DSN` host is reachable from the app server |
| Tunnel: `503 watchtower_dsn_missing` | `WATCHTOWER_DSN` not set in `.env` | Set it, then `php artisan config:clear` |
| Either: ingest returns 200 but issue page stays empty | Watchtower's Horizon `ingest` queue is stalled | Contact the Watchtower admin |
| Either: `429 Too Many Requests` from ingest | Per-minute rate limit hit | Back off, or ask admin to raise `rate_limit_per_min` for the project |
| `/api/v1/...` returns 401 even with the DSN key | Token revoked / DSN regenerated | Get a fresh DSN from project settings |
| `/api/v1/events/{id}` returns 404 right after sending | Async ingestion hasn't drained yet | Retry after 2–5s |
| MCP: `claude mcp add` succeeds but tool calls fail with `Unauthenticated` | Bearer token revoked / wrong DSN | Re-copy DSN from project settings; re-run `claude mcp add` |
| MCP: tool says "Issue not found in this team" but the issue is visible in the Watchtower UI | The issue belongs to a project under a different team than the DSN key you registered | Use a DSN key from the right team, or move the project to this team via team settings |

## Updating this skill

This skill is shipped by the `phattarachai/watchtower-laravel` composer package and discovered by Laravel Boost from `vendor/phattarachai/watchtower-laravel/resources/boost/skills/`. To refresh:

```bash
composer update phattarachai/watchtower-laravel
php artisan boost:install --skills
```

Boost re-copies the skill files into `.claude/skills/watchtower-error-tracking/` (and the agent-specific equivalents for Cursor, Codex, etc.). The `version:` field in the frontmatter tracks releases.

## Roadmap

This skill is feature-complete for Phase 6. Remaining items, deferred until real need shows up:

- Per-user / per-team-member API tokens. Today any team member's DSN public_key authenticates against the full team — adequate for small teams, may want tighter scoping later.
- Bulk triage tools (`resolve_many`, `snooze_many`). Not needed until agents complain about one-at-a-time mutations.
