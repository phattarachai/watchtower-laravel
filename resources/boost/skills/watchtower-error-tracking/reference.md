---
name: watchtower-error-tracking-reference
description: End-to-end install reference for wiring Watchtower error tracking into a new project. Covers Laravel backends (via the phattarachai/watchtower-laravel package, one command — including the package's smart defaults: multi-guard user-context middleware, BeforeSend noise filter + secret scrubbing, breadcrumb env keys), browser JavaScript frontends (via @sentry/browser with the tunnel option and the published applyWatchtowerUser helper), the verify-via-REST flow, the project-scoped MCP server for in-conversation triage from Claude Code, and troubleshooting. Read this when SKILL.md directs you here, or when the user wants to install, configure, verify, triage, or troubleshoot Watchtower error tracking.
version: 2026.05.18.2
---

# Watchtower install — reference

Companion to [`SKILL.md`](SKILL.md). This is the canonical install guide. Covers Laravel + browser JavaScript end-to-end.

## What Watchtower is

Self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs (`sentry/sentry-laravel` on the backend, `@sentry/browser` in the browser) pointed at your Watchtower instance. **Exception-only** — Watchtower drops trace / transaction (performance) items at ingest, so do not enable performance monitoring on the client side.

## Pick the right Watchtower project for this codebase

Default: **one Watchtower project per client app**. The same DSN authenticates the Laravel backend (`SENTRY_LARAVEL_DSN`) and the browser frontend (`VITE_SENTRY_DSN`). `watchtower:install` writes both env keys to that DSN. Backend exceptions and JS errors share one inbox; project name in the breadcrumb tells them apart at triage time. Create the project once at `https://watchtower.phattarachai.app/projects/create`, pick the primary runtime (usually `php-laravel`) as `platform`, copy the DSN.

### When to split into two projects

The trade-off of the shared-DSN default is that the frontend DSN ships in the JS bundle, so a leaked key can be used to forge events against the same project. Watchtower's per-project rate limit caps the blast radius. Split into a second project when one of these applies:

| Situation | Why split |
|---|---|
| Regulated environment | Auditors want server-only and browser-only signals isolated. |
| Public-facing / high-traffic frontend | A forged-events flood would drown out backend triage. |
| Very noisy frontend (third-party scripts, extensions) | Different cooldowns, different recipients, different rate limits than backend. |
| Different alert recipients per runtime | Frontend team owns `javascript`; backend team owns `php-laravel`. |

To split: after `watchtower:install`, edit `.env` to point `VITE_SENTRY_DSN` at a second Watchtower project's DSN. Each Watchtower project authenticates its own MCP server — register a second one with `claude mcp add watchtower-frontend <host>/mcp --header "Authorization: Bearer <frontend-public-key>"` so Claude can triage both inboxes.

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

`watchtower:install` does eight things:

1. **Resolves the DSN.** From `--dsn=…`, then `WATCHTOWER_DSN`, then `SENTRY_LARAVEL_DSN`, otherwise prompts. Validates the DSN format (numeric project id required).
2. **Writes env keys.** Sets `WATCHTOWER_DSN` and `SENTRY_LARAVEL_DSN` in `.env`. Empty in `.env.example`. For `APP_ENV=local`, prompts whether to use `null` (recommended — don't flood Watchtower from dev).
3. **Patches `bootstrap/app.php`.** Adds `use Sentry\Laravel\Integration;` and inserts `Integration::handles($exceptions);` inside `withExceptions(...)`. **Critical** — without this, unhandled exceptions in HTTP requests, jobs, and commands never reach Watchtower. If the package can't recognize the file's shape, it prints the diff and bails — apply it by hand.
4. **Publishes `config/watchtower.php`.** Knobs: relay path, timeout, async forwarding flag, queue name, plus the `user_context` + `before_send` sections that power the [Smart defaults](#smart-defaults).
5. **Detects Vite.** If `vite.config.{js,ts}` exists, writes `VITE_SENTRY_DSN` (same value as `SENTRY_LARAVEL_DSN`), `VITE_SENTRY_TUNNEL=/api/watchtower-relay`, and `VITE_SENTRY_ENVIRONMENT="${APP_ENV}"` to `.env` and `.env.example`. Prints the `Sentry.init(...)` snippet to paste into the entry JS file (see [Browser-side init](#browser-side-init) below). To point the browser side at a *different* Watchtower project, edit `VITE_SENTRY_DSN` in `.env` after install — see [When to split into two projects](#when-to-split-into-two-projects).
6. **Confirms `SENTRY_SEND_DEFAULT_PII`.** Prompts `Enable SENTRY_SEND_DEFAULT_PII (attach request data + IP — Watchtower scrubs secrets via BeforeSend)?` — **opt-in**, defaults to `no` across every env (regulated industries and casual installs both get the conservative default). Writes `SENTRY_SEND_DEFAULT_PII=true|false`. Without PII the SDK strips the request body + IP before any local filter sees them — the User tab and Request tab in Watchtower stay empty. Flip to `yes` only after confirming `BeforeSend`'s scrub coverage (step 4 config) is sufficient for your app's data; that's what makes it safe to leave on in production.
7. **Writes Sentry breadcrumb env keys** (only when absent — re-runs preserve customization): `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=true`, `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED=false` (bindings can leak PII even after scrubbing — opt in if you need them), `SENTRY_BREADCRUMBS_CACHE_ENABLED=true`, `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED=true`, `SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED=true`. Result: events arrive with the last ~100 query/cache/HTTP/Redis ops in Watchtower's Breadcrumbs tab.
8. **Publishes the browser user-context helper** (Vite path only). Calls `vendor:publish --tag=watchtower-js --force=false` → drops `resources/js/vendor/watchtower-user-context.js`. The printed `Sentry.init(...)` snippet imports and calls `applyWatchtowerUser()` from this file; a separate `<meta name="watchtower-user-*">` snippet is printed for the root Blade layout. See [Browser user context](#browser-user-context) below.

Then it registers the project-scoped MCP server with Claude Code if the `claude` CLI is on PATH (see [Querying via MCP](#querying-via-mcp)).

Flags:
- `--dsn=…` — Watchtower DSN. Skips the prompt.
- `--dry-run` — print intended changes (env diff + `bootstrap/app.php` unified diff) without writing.
- `--no-mcp` — skip registering the Watchtower HTTP MCP server with Claude Code.

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
| `WATCHTOWER_USER_CONTEXT` | `true` | Set `false` to disable the auto-pushed [user context middleware](#user-context-middleware) entirely. |
| `WATCHTOWER_USER_CONTEXT_GUARDS` | `auto` | `auto` walks `array_keys(config('auth.guards'))`; or a comma-separated list (`web,admin,api`) to fix priority and narrow the set. First authenticated guard wins. |
| `WATCHTOWER_BEFORE_SEND` | `true` | Set `false` to skip the [BeforeSend pipeline](#beforesend-pipeline) (no noise drop, no scrubbing). |
| `SENTRY_SEND_DEFAULT_PII` | (prompted at install) | Sentry SDK key. With it on, request body + IP attach to events (Watchtower's BeforeSend is the safety net that strips secrets). Without it, User tab + Request tab stay empty. |
| `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED` | (`true` after install) | Sentry SDK key. Attach SQL query crumbs. |
| `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED` | (`false` after install) | Sentry SDK key. Bindings can leak PII even after scrubbing — opt in deliberately. |
| `SENTRY_BREADCRUMBS_CACHE_ENABLED` | (`true` after install) | Sentry SDK key. Cache get/set crumbs. |
| `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED` | (`true` after install) | Sentry SDK key. Outbound HTTP crumbs via Laravel's HTTP client. |
| `SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED` | (`true` after install) | Sentry SDK key. Redis command crumbs. |

Two more knobs live in `config/watchtower.php` only (arrays — too much for an env var): `watchtower.user_context.fields` (see [User context middleware](#user-context-middleware) below) and `watchtower.before_send.{ignored_exceptions, scrub_keys}` (see [BeforeSend pipeline](#beforesend-pipeline) below).

## Smart defaults

Out of the box, `watchtower:install` produces events that already populate Watchtower's User tab, hide framework noise from the inbox, and never leak secrets in request bodies / headers. Each behavior is reversible via env or config.

### User context middleware

`Phattarachai\WatchtowerLaravel\Http\Middleware\WatchtowerUserContext` is auto-pushed onto both the `web` and `api` route groups when the service provider boots (unless `WATCHTOWER_USER_CONTEXT=false`). On every request it:

1. Walks the configured guards in order — `config('auth.guards')` keys when `WATCHTOWER_USER_CONTEXT_GUARDS=auto`, or the explicit comma-separated list when set (`web,admin,api`).
2. First authenticated guard wins. The middleware grabs the `Authenticatable` and remembers the guard name.
3. Builds a payload and calls `\Sentry\configureScope(fn ($scope) => $scope->setUser($payload)->setTag('auth.guard', $guard))`.

Payload behavior depends on `watchtower.user_context.fields`:

- **`[]` (default — auto-discover)** — every attribute on the user model except (a) anything in the model's `$hidden` array and (b) the package's hard-coded deny-list (`password`, `password_hash`, `remember_token`, `api_token`, `two_factor_secret`, `two_factor_recovery_codes`). `id` falls back to `getAuthIdentifier()` (survives renamed primary keys), `ip_address` always comes from `$request->ip()`. The `name` column is mapped to Sentry's `username` (Sentry's user schema uses `username` for the display name, not `name`).
- **Explicit list (`['id', 'email']`)** — only those fields. Allowed values: `id`, `email`, `name` (mapped to `username`), `ip_address`.

The middleware swallows exceptions from broken auth drivers — a misconfigured guard never breaks a request, it just means the User tab won't populate for that request.

Result in Watchtower: `IssueDetail` → User tab shows id / email / username / ip rows; Custom tab shows the `auth.guard` pill. Multi-guard apps (web humans + API mobile clients + admin back-office) get an at-a-glance signal of which surface produced the exception.

### BeforeSend pipeline

`Phattarachai\WatchtowerLaravel\Sentry\BeforeSend` is chained in front of any existing `before_send` callback from `config/sentry.php` at service-provider boot. Composition order: ours runs first (drop + scrub); if the event survives, the user's existing callback runs after. Either can return `null` to drop.

Two responsibilities:

**Drop framework noise.** If the event's hinted exception is an instance of any class in `watchtower.before_send.ignored_exceptions`, the event is dropped (returns `null`). Defaults:

```
Illuminate\Validation\ValidationException
Illuminate\Auth\AuthenticationException
Illuminate\Auth\Access\AuthorizationException
Illuminate\Database\Eloquent\ModelNotFoundException
Illuminate\Session\TokenMismatchException
Symfony\Component\HttpKernel\Exception\NotFoundHttpException
Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException
```

Laravel's HTTP handler already excludes most of these via `$internalDontReport`, but the BeforeSend list is belt-and-braces — it also catches direct `\Sentry\captureException()` calls from queue jobs or console code that bypasses the HTTP handler. Extend per project. Don't subtract unless you actually want validation / auth-fail / 404 noise in the inbox.

**Scrub secrets.** For each event that survives the drop check, the middleware walks `event.request.data`, `event.request.headers`, `event.request.cookies`, and `event.extra`. For each entry whose key (case-insensitive) matches `watchtower.before_send.scrub_keys`, the value becomes the literal string `[Filtered]`. Defaults:

```
password, password_confirmation, current_password,
token, api_key, secret, authorization, cookie,
credit_card, card, cvv, cvc
```

Then a credit-card-shape regex (`/\b\d(?:[ -]?\d){12,18}\b/`) sweeps the remaining string values and replaces any 13–19-digit number with `[Filtered]`. Intentionally permissive — false positives just redact an innocent number; false negatives leak a real card.

Result in Watchtower: matching exceptions never reach the inbox at all. Surviving events render the literal string `[Filtered]` in the Request tab's headers `<dl>` and body `<pre>`.

To extend either list, publish `config/watchtower.php` and edit the arrays directly. Both are merged from the package defaults at boot, so a published copy is the full picture.

### Breadcrumb env keys

`watchtower:install` writes five `SENTRY_BREADCRUMBS_*` env keys on first run (`setIfAbsent` — re-runs preserve user customization):

| Env key | Default after install | Why |
|---|---|---|
| `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED` | `true` | The query itself is the highest-signal breadcrumb when triaging a DB-shaped exception. |
| `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED` | `false` | Bindings carry the actual user data — emails, IDs, sometimes free text. Even with BeforeSend scrubbing, prefer off by default. Opt in when bindings are essential. |
| `SENTRY_BREADCRUMBS_CACHE_ENABLED` | `true` | Cache key + hit/miss is cheap and useful. |
| `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED` | `true` | Outbound HTTP calls via Laravel's HTTP client; URL + status + duration. |
| `SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED` | `true` | Redis commands (excluding payloads); useful for queue / cache / lock debugging. |

Set any specific key to `false` in `.env` to disable that family without disabling the rest.

### Browser user context

The install command publishes `resources/js/vendor/watchtower-user-context.js` and prints two snippets to paste:

1. Updated `Sentry.init(...)` block — imports `applyWatchtowerUser` from the published helper and calls it after `Sentry.init`. The helper reads `<meta name="watchtower-user-{id,email,name}">` from the document and calls `Sentry.setUser({id, email, username})` with whatever values are present (no-op when no meta tags are found).
2. `<meta name="watchtower-user-*">` block — paste into the root Blade layout's `<head>`, rendered from `auth()->id() / ->user()?->email / ->user()?->name`.

The result: browser-side exceptions get the same User tab population as server-side, with the same `id`/`email`/`username` mapping.

The published helper is yours to edit — `--force=false` on re-runs means customizations are preserved. The package version is the source of truth in `vendor/phattarachai/watchtower-laravel/resources/js/watchtower-user-context.js`.

### Recommended `.env` per environment

| Environment | `SENTRY_LARAVEL_DSN` | `VITE_SENTRY_DSN` | `WATCHTOWER_RELAY_ASYNC` |
|---|---|---|---|
| Production | full DSN | same DSN | `true` (if Horizon present) |
| Staging | full DSN | same DSN | `true` |
| Local dev | `null` | full DSN (for browser dev) or `null` | `false` |

For the rare cases that warrant splitting backend and frontend into two Watchtower projects, set `VITE_SENTRY_DSN` to the second project's DSN — see [When to split into two projects](#when-to-split-into-two-projects).

**Do not set `SENTRY_TRACES_SAMPLE_RATE`.** Watchtower drops trace items.

## Browser-side init

The install command writes the env keys, publishes the user-context helper, and prints two snippets — but does NOT modify your entry JS file or your Blade layout. Those one-time pastes are on you.

In `resources/js/app.js` (or wherever your bundler entry is), add at the top:

```javascript
import * as Sentry from '@sentry/browser';
import { applyWatchtowerUser } from './vendor/watchtower-user-context.js';

if (import.meta.env.VITE_SENTRY_DSN) {
    Sentry.init({
        dsn: import.meta.env.VITE_SENTRY_DSN,
        tunnel: import.meta.env.VITE_SENTRY_TUNNEL,  // /api/watchtower-relay
        environment: import.meta.env.VITE_SENTRY_ENVIRONMENT ?? 'production',
        tracesSampleRate: 0,
        sendDefaultPii: false,

        // Drop noise from browser extensions, sandboxed iframes, and known
        // ad-blocker injection points. Safe to keep on; rarely a false positive.
        denyUrls: [
            /extensions\//i,
            /^chrome:\/\//i,
            /^chrome-extension:\/\//i,
            /^moz-extension:\/\//i,
            /^safari-extension:\/\//i,
            /^safari-web-extension:\/\//i,
        ],

        // ignoreErrors: [
        //     // Network noise that's the user's connection, not your bug:
        //     'Network request failed',
        //     'NetworkError',
        //     'Failed to fetch',
        //     // Benign ResizeObserver chatter from layout shifts:
        //     'ResizeObserver loop limit exceeded',
        //     'ResizeObserver loop completed with undelivered notifications',
        //     // Safari quirks:
        //     'Non-Error promise rejection captured',
        // ],
    });

    applyWatchtowerUser();
}
```

Then in your root Blade layout's `<head>` — `resources/views/components/layouts/app.blade.php` or equivalent — paste:

```blade
<meta name="watchtower-user-id" content="{{ auth()->id() ?? '' }}">
<meta name="watchtower-user-email" content="{{ auth()->user()?->email ?? '' }}">
<meta name="watchtower-user-name" content="{{ auth()->user()?->name ?? '' }}">
```

Then:

```bash
npm install --save @sentry/browser
npm run build
```

The `tunnel` option is non-negotiable for production deployments. Without it, ~10–30% of users with ad blockers will silently fail to report errors. The `applyWatchtowerUser()` call after `Sentry.init` is what fills Watchtower's User tab for browser-side exceptions — without it, you get the same User tab population gap on the browser side that disabling the middleware causes on the server side. Leave `ignoreErrors` commented at first; turn individual lines on once you've seen the actual noise in your project's Watchtower inbox.

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
| GET | `/api/v1/events` | List recent events (slim shape — id, level, env, top_frame). Filters: `environment`, `release`, `level`, `since`, `group_id`. Pagination: `page`, `per_page` (1–100, default 25). |
| GET | `/api/v1/events/{event_id}` | Full event payload (stacktrace frames, breadcrumbs, request, contexts, tags, extra, sdk, mechanism) — verification core AND debugging entry point. |

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

Watchtower exposes a Model Context Protocol server at `/mcp` for Claude Code (and any other MCP client). Same auth model as REST — the DSN public_key is the bearer token — and **project-scoped**: each MCP server gives Claude access to one Watchtower project, matching how the project-scoped REST endpoints work. With the package default of one Watchtower project per client app (backend + browser share the DSN), a single MCP registration covers both runtimes.

### Add the server

`php artisan watchtower:install` registers the MCP server with Claude Code automatically when the `claude` CLI is on PATH (use `--no-mcp` to skip). To register manually after the fact:

```bash
claude mcp add watchtower https://watchtower.phattarachai.app/mcp \
  --header "Authorization: Bearer <PUBLIC_KEY>"
```

Extract `<PUBLIC_KEY>` from the project's `.env`:

```bash
grep -oE '://[a-z0-9]+@' .env | head -1 | tr -d ':/@'
```

If you actually split backend and browser into two Watchtower projects (rare — see [When to split into two projects](#when-to-split-into-two-projects)), register one MCP server per project with distinct names so Claude can address each inbox:

```bash
claude mcp add watchtower-backend  <host>/mcp --header "Authorization: Bearer <backend-public-key>"
claude mcp add watchtower-frontend <host>/mcp --header "Authorization: Bearer <frontend-public-key>"
```

### Tool reference

| Tool | Args | Returns |
|---|---|---|
| `get_stats` | `window?` (`24h`/`7d`/`30d`, default `7d`) | Event volume + per-level breakdown + top-5 issues in window + status_mix |
| `list_issues` | `status?`, `environment?`, `level?`, `q?`, `since?`, `page?` | Paginated issue groups for this project, newest-seen first |
| `get_issue` | `issue_id` | One issue group + permalink to the Watchtower UI + **`latest_event_id`** (skip the `list_events` round-trip — feed it straight to `get_event`) |
| `list_events` | `environment?`, `release?`, `level?`, `group_id?`, `since?`, `page?`, `per_page?` (1–100, default 25) | Paginated events for this project (slim shape — id, level, env, top_frame), newest first |
| `get_event` | `event_id` (UUID) | **Full event payload** — slim fields plus `stacktrace` frames, `breadcrumbs`, `request`, `contexts`, `tags`, `extra`, `sdk`, `mechanism`. Verification core AND debugging entry point. |
| `resolve_issue` | `issue_id` | Marks resolved; re-opens automatically on regression in a higher release |
| `ignore_issue` | `issue_id` | Marks ignored; does NOT re-open on new events |
| `unresolve_issue` | `issue_id` | Re-opens a resolved or ignored issue |
| `snooze_issue` | `issue_id`, `duration` (`1h`/`24h`/`7d`/`30d`/`until_event`) | Snoozes; `until_event` un-snoozes on the next event |

### Verifying an exception via MCP

After capturing an exception in the client app, ask Claude:

> "Use Watchtower to verify event_id `<uuid>` arrived."

Claude calls `get_event`. Success → ingested (and the response includes the full stacktrace + breadcrumbs, so the same call doubles as a debugging starting point). An error response saying *"Event not found in this project. The ingest pipeline is async — retry after a few seconds…"* → the queue hasn't drained yet, try again in a moment.

### Debug an issue end-to-end via MCP

The common "fix issue N" flow is a 2-call path:

> "Fix Watchtower issue #44."

1. `get_issue(issue_id=44)` — title, status, counts, permalink, **and `latest_event_id`**.
2. `get_event(event_id=<latest_event_id>)` — full payload. The agent now has the stacktrace (with `in_app` flags), breadcrumbs (the user actions leading up to the error), request URL/method, contexts, tags, and SDK info needed to open the right file and propose a fix.
3. After the fix lands and is verified, `resolve_issue(issue_id=44)`. Watchtower auto-reopens the issue if a new event arrives in a later release.

### Privilege scope

DSN holder gets read **and** mutate access for that one project. Per-user / per-team-member tokens are not implemented yet — same boundary as the REST endpoints.

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
| MCP: tool says "Issue not found in this project" but the issue is visible in the Watchtower UI | The issue belongs to a different Watchtower project than the DSN key you registered | Re-run `claude mcp add` with the DSN key for the project that owns the issue, or register a second MCP server alongside the first with a distinct name |
| User tab in Watchtower is empty despite a logged-in user triggering the exception | Smart-defaults chain broken at one of three points | Check (a) `SENTRY_SEND_DEFAULT_PII=true` — without it Sentry strips request data + IP before BeforeSend runs; (b) `WATCHTOWER_USER_CONTEXT` not set to `false`; (c) the right guard is listed in `WATCHTOWER_USER_CONTEXT_GUARDS` (or it's `auto`); (d) for browser-side, the `<meta name="watchtower-user-*">` tags are in `<head>` AND `applyWatchtowerUser()` is called after `Sentry.init`. See [Smart defaults](#smart-defaults). |
| Inbox is suddenly missing `ValidationException` / `404` / auth-fail events | BeforeSend smart-default is dropping them — this is the design | If you actually want these in the inbox, edit `watchtower.before_send.ignored_exceptions` and remove the relevant class, or set `WATCHTOWER_BEFORE_SEND=false` to disable the filter entirely. |
| Request body in event payload shows `[Filtered]` for a non-secret field | The field name matches an entry in `watchtower.before_send.scrub_keys` (case-insensitive) | Edit `watchtower.before_send.scrub_keys` to remove the entry, or rename the field. |

## Updating this skill

This skill is shipped by the `phattarachai/watchtower-laravel` composer package and discovered by Laravel Boost from `vendor/phattarachai/watchtower-laravel/resources/boost/skills/`. To refresh:

```bash
composer update phattarachai/watchtower-laravel
php artisan boost:install --skills
```

Boost re-copies the skill files into `.claude/skills/watchtower-error-tracking/` (and the agent-specific equivalents for Cursor, Codex, etc.). The `version:` field in the frontmatter tracks releases.

## Roadmap

This skill is feature-complete for Phase 6. Remaining items, deferred until real need shows up:

- Per-user / per-team-member API tokens. Today the project's DSN public_key is the only credential — anyone with it gets read + mutate access for that project. Adequate for small teams; revisit when trust boundaries inside a project start mattering.
- Bulk triage tools (`resolve_many`, `snooze_many`). Not needed until agents complain about one-at-a-time mutations.
