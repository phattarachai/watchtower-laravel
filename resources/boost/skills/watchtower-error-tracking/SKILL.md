---
name: watchtower-error-tracking
description: "Wire Watchtower (self-hosted, Sentry-compatible) into a Laravel or browser app, and triage its issues over MCP, down to sweeping the production inbox. Triggers on \"Watchtower\", \"set up error tracking\", \"sweep production issues\", \"verify the exception was reported\", WATCHTOWER_DSN, or SENTRY_LARAVEL_DSN."
version: 2026.10.31.1
---

# Watchtower error tracking

Watchtower is a self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs pointed at a Watchtower instance.

**Scope of this skill:** everything past the one-paragraph blurb Boost auto-injects into the project's CLAUDE.md (`resources/boost/guidelines/core.md`) — what the package wires up, install, triage, verification, troubleshooting. For full env-key tables and REST endpoint shapes see [`reference.md`](reference.md).

## What the package wires up

- **Backend reporting** — `sentry/sentry-laravel` pointed at the Watchtower instance via `SENTRY_LARAVEL_DSN`, with `Integration::handles($exceptions)` wired into `bootstrap/app.php`.
- **Browser reporting** — `@sentry/browser` pointed at `VITE_SENTRY_DSN` and tunnelled through the same-origin `/api/watchtower-relay` route, so ad blockers don't drop envelopes.
- **User context** — the `WatchtowerUserContext` middleware plus the `@watchtowerUser` Blade directive, so events arrive with the signed-in user attached.
- **Noise filtering + scrubbing** — a `before_send` pipeline that drops framework exceptions (validation, auth, 404, CSRF, …) and strips secrets from request data, headers, cookies, URLs, and extra.
- **MCP server** — a project-scoped `watchtower` entry in `.mcp.json` exposing `mcp__watchtower__*` tools for issue triage from inside Claude Code.

Config lives in `config/watchtower.php`; every default above is opt-out via env (see [Smart defaults](#smart-defaults-laravel-package)). `php artisan watchtower:test` verifies backend, relay, and frontend wiring end to end.

## Pick a mode first

`WATCHTOWER_MODE` decides whether this app *reports to* a Watchtower or *is* one. Ask before installing if it isn't obvious.

| Mode | Choose it when | What you get |
|---|---|---|
| `relay` (default) | There's a central Watchtower and this app is one of several reporting to it. | Everything in the list above. No tables, no UI. |
| `standalone` | One app, no second host to run, or the errors must not leave this machine. Also the right answer when the app has child services (a WordPress site, a Next.js frontend) that need somewhere to report. | Own `watchtower_*` tables, ingest endpoint, Inertia console at `/{prefix}` (Inertia + React hosts only), alert emails, MCP server. |
| `dual` | Browser errors should keep flowing to the central fleet inbox, but server-side detail stays local. | Both — but note the asymmetry below. |

Relay and dual need a central Watchtower server. In the commands below, `<watchtower-host>` is that server's host: the part after `@` in `WATCHTOWER_DSN`. The browser relay forwards to `/api/watchtower-relay` on it, an endpoint only a Watchtower server has, so a relay DSN can't point at sentry.io.

> **Dual is not a mirror.** Browser envelopes are stored locally *and* forwarded upstream; this app's own backend exceptions land only in the local store, because the install points `SENTRY_LARAVEL_DSN` at the local project.

### Standalone install

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install --standalone   # or --mode=dual (also prompts for the upstream DSN)
npm run build
php artisan watchtower:doctor
```

`--standalone` runs the relay steps (env keys, `bootstrap/app.php` patch, config publish, frontend wiring) *plus*: writes `WATCHTOWER_MODE`, migrates six `watchtower_*` tables, creates the first project and points `SENTRY_LARAVEL_DSN` at its local DSN, and registers the local MCP server. When `inertiajs/inertia-laravel` is installed it also publishes `resources/js/pages/Watchtower.jsx` (`.tsx` in a TypeScript host), adds the `@watchtower` alias to `vite.config` and writes `resources/css/watchtower.css`; without Inertia it skips those files and says how to add the UI later.

Then do the one thing it can't: **grant access to the console.** It's wide open in `local` and 403 everywhere else until you register a check.

```php
use Phattarachai\WatchtowerLaravel\Watchtower;

Watchtower::auth(fn ($request): bool => $request->user()?->isAdmin() === true);
// …or define a `viewWatchtower` gate instead.
```

`watchtower:doctor` is the verification step here, not `watchtower:test` — it checks tables, routes, the published page, the Vite alias, the two Tailwind lines, an active project, and that self-capture is actually bound. Treat every failure as real; the advisories (mailer, queue driver, `laravel/mcp` missing) are informational.

**Upgrading:** `composer update phattarachai/watchtower-laravel`, then `php artisan vendor:publish --tag=watchtower-inertia --force` (it writes `Watchtower.tsx` in a TypeScript host, `.jsx` otherwise) and `watchtower:doctor`. Don't re-publish `config/watchtower.php` for new keys: missing keys are filled from the package defaults at any depth, and published lists are kept as written. If both `Watchtower.jsx` and `Watchtower.tsx` exist (a 1.5.0 re-publish in a TypeScript host), delete the `.jsx`; doctor warns about it.

Requirements: PHP 8.4+, Laravel 12 or 13. The console additionally needs **`inertiajs/inertia-laravel`** (suggested, not required by the package) on an **Inertia + React** host with an `import.meta.glob` over `resources/js/pages`, plus **Tailwind v4** via Vite. The install writes a separate `resources/css/watchtower.css` (`@import 'tailwindcss' prefix(tw) source(none);` plus an `@source` line into the package) that the page imports, so the host's own Tailwind setup is untouched. **Livewire, Filament or Blade hosts** run headless: set `WATCHTOWER_UI_ENABLED=false` and they keep ingest, self-capture, alerts and MCP; only the console page is missing. `watchtower:doctor` fails while the UI is enabled without Inertia and names the fix. No Redis is needed; the `sync` queue works.

Manage projects without the browser: `php artisan watchtower:project {list|create|rotate-key|activate|deactivate}`. `list` prints each project's key and DSN.

### Running standalone in production

On anything with a real queue (Redis + Horizon), do three things `watchtower:install` deliberately leaves to you:

1. **Give Watchtower its own queue.** Add a Horizon supervisor for a `watchtower` queue, deploy it, *then* set `WATCHTOWER_QUEUE_NAME=watchtower` — in that order, because a queue nobody listens on silently piles up events. `watchtower:doctor` fails when Horizon has no supervisor for the queue in the current environment. The package default is still the host's `default` queue, so an upgrade never strands jobs.

   ```php
   // config/horizon.php — add to 'defaults', and list it under each environment
   'app-supervisor-watchtower' => [
       'connection' => 'redis',
       'queue' => ['watchtower'],
       'balance' => false,
       'maxProcesses' => 1,
       'tries' => 3,
       'timeout' => 60,   // > ProcessEventJob's 30 s, < the connection's retry_after
   ],
   ```

2. **Cap Redis.** `maxmemory 1gb` + `maxmemory-policy noeviction` in `redis.conf`. A full Redis then refuses writes — self-capture drops the event and the request carries on — instead of growing until the kernel OOM-kills it and every request 500s.

3. **Know the self-capture loop.** Before 1.2.0 a failing `ProcessEventJob` was reported by the worker, captured into a *new* `ProcessEventJob`, and so on — each generation quoting the last one's payload in its SQL error (one incident: 7 GB queue, Redis OOM-killed 62 times). 1.2.0 drops Watchtower's own job failures from self-capture, rate-limits every ingest path, and caps each queued event at 200 KB; 1.3.0 also stops queueing past `WATCHTOWER_MAX_QUEUE_DEPTH` (5000) jobs, so a dead Horizon can't fill Redis. If Redis memory still balloons with `ProcessEventJob` payloads, follow the triage runbook in `reference.md` § "Self-capture loop".


### Pointing child apps at a standalone host

Any Sentry SDK — WordPress, Next.js, another Laravel service — reports to the standalone host with a DSN of this shape:

```
{scheme}://{public_key}@{host}/{prefix}/{project_id}
```

e.g. `https://ab12…@shop.test/watchtower/2`. Create the project with `watchtower:project create "Storefront"` and copy the DSN it prints. **The prefix goes in front of the project id and there is no `api` segment** — the SDK appends `/api/{project_id}/envelope/` itself, so that DSN resolves to `https://shop.test/watchtower/api/2/envelope/`. Adding `api` yourself, or dropping the prefix, produces a silent 404 per event. Browser SDKs on a different origin still want a `tunnel` (see `reference.md` § "Browser-only setup").

## Provision the project (headless)

*Relay mode only — a standalone install creates its own first project during `watchtower:install`.*

If you don't yet have a DSN, you can create the Watchtower project and mint one without
leaving the terminal — no clicking through the UI. This needs a **Personal Access Token**
(PAT) stored once on the dev machine at `~/.watchtower/token`.

```bash
PAT=${WATCHTOWER_TOKEN:-$(cat ~/.watchtower/token 2>/dev/null)}
if [ -n "$PAT" ]; then
  DSN=$(curl -fsS -X POST https://<watchtower-host>/api/v1/projects \
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
echo 'export WATCHTOWER_TOKEN="$(cat ~/.watchtower/token 2>/dev/null)"' >> ~/.zshrc
```

The same PAT is the read credential for the REST API and the MCP server below, which Claude Code reads from
`WATCHTOWER_TOKEN` in its own environment. Every machine that runs Claude Code (a laptop, a server running agent
sessions) needs its own PAT exported, and Claude Code must be restarted after the export.

**No PAT?** Fall back to the manual onboarding flow below — create the project in the
Watchtower UI, copy its DSN, and run `watchtower:install` (it prompts for the DSN).

## Install in 3 commands (relay mode)

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
| `BeforeSend` filter (chained in front of any existing `before_send` from `config/sentry.php`) | Drops 9 framework exception classes before egress (`ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`, `TokenMismatchException`, `NotFoundHttpException`, `MethodNotAllowedHttpException`, `AccessDeniedHttpException`, `SuspiciousOperationException`) and scrubs request `data` / `headers` + event `extra` for known secret keys (case-insensitive) — `password`, `token`, `_token`, `api_key`, `secret`, `authorization`, `cookie`, `x-csrf-token`, `x-xsrf-token`, `credit_card`, `cvv`, etc. Every cookie value is filtered (names kept). Query values with those keys (and a signed URL's `signature`) are filtered in `request.url` / `request.query_string`; a token in the URL path needs a regex in `scrub_url_patterns` (empty by default). Credit-card-shape regex sweeps remaining string values. The scrub also runs on sampled transactions (`before_send_transaction`). | `WATCHTOWER_BEFORE_SEND=false`, or edit `watchtower.before_send.{ignored_exceptions, scrub_keys, scrub_url_patterns}` |
| Breadcrumb env keys written by `watchtower:install` (only when absent) | `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED=true`, `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED=false` (bindings can leak PII even after scrubbing), `SENTRY_BREADCRUMBS_CACHE_ENABLED=true`, `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED=true`, `SENTRY_BREADCRUMBS_REDIS_COMMANDS_ENABLED=true`. Result: events arrive with the last ~100 query/cache/HTTP/Redis ops attached — Watchtower's Breadcrumbs tab fills in. | Set any individual `SENTRY_BREADCRUMBS_*` key to `false` in `.env` |
| `SENTRY_SEND_DEFAULT_PII` install prompt | **Opt-in**: defaults to `no` across every env (regulated industries and casual installs both get the conservative default). Without it, the Sentry SDK strips the request data + IP *before* `BeforeSend` runs — User tab + Request tab stay empty. Flip to `yes` only after confirming `BeforeSend`'s scrub coverage is sufficient for your app's data; then `BeforeSend` becomes the scrubbing safety net that makes it safe to leave on. | Answer `yes` at the prompt, or set `SENTRY_SEND_DEFAULT_PII=true` in `.env` after install |
| Browser: `initWatchtower()` helper (`resources/js/vendor/watchtower.js`) | Single-call entry-point that runs `Sentry.init(...)` with Watchtower-tuned defaults (same-origin tunnel, `denyUrls` for browser extensions, no PII) and then reads `<meta name="watchtower-user-{id,email,name}">` from the document to call `Sentry.setUser(...)`. Per-entry footprint is `import { initWatchtower } from './vendor/watchtower.js'; initWatchtower();` — the Sentry config lives inside the helper, so multiple entries don't duplicate it. The install command publishes the helper; `--patch-js` injects the import into detected Vite entries; `--patch-views` injects the `@watchtowerUser` directive into detected layouts. | Don't paste the directive, or don't call the helper |
| Blade: `@watchtowerUser` directive | Compiles to the three `<meta name="watchtower-user-{id,email,name}">` tags read by the browser helper. Registered automatically by the package. The source view lives at `vendor/phattarachai/watchtower-laravel/resources/views/user-meta.blade.php`; publish with `php artisan vendor:publish --tag=watchtower-views` to override at `resources/views/vendor/watchtower/user-meta.blade.php`. For Filament admin panels, register a `PanelsRenderHook::HEAD_END` hook that returns `Blade::render('@watchtowerUser')`. | Don't add `@watchtowerUser` to the layout / panel hook |

Config reference (`config/watchtower.php` → `user_context` and `before_send` sections) and the full env-key table live in `reference.md` § "Smart defaults".

## Verifying an exception via REST

When MCP isn't available, query the REST API with your personal access token (`WATCHTOWER_TOKEN`, see the bootstrap
above). The DSN's public key only ingests: it ships to every browser, so it can't read anything.

```bash
EVENT_ID=$(php artisan tinker --execute 'echo \Sentry\captureMessage("watchtower-probe-".now())->__toString();')
PROJECT_ID=$(grep -E '^SENTRY_LARAVEL_DSN=' .env | grep -oE '[0-9]+$')   # the DSN's numeric project segment

curl -fsSL -H "Authorization: Bearer $WATCHTOWER_TOKEN" \
  https://<watchtower-host>/api/v1/projects/$PROJECT_ID/events/$EVENT_ID
```

200 → ingested. 404 → not received yet (the queue is async; retry after a few seconds). Full endpoint list in `reference.md` § "Querying via REST".

## MCP server

Watchtower exposes an MCP server at `/mcp/{project}` (project id or slug) so Claude can query and triage issues directly. Each registration is scoped to one Watchtower project — same as the project-scoped REST endpoints. The package's default of one Watchtower project per client app means a single registration covers backend exceptions and browser exceptions in the same inbox.

`watchtower:install` registers it automatically when the `claude` CLI is on PATH, using `--scope project` so the config lands in a committed `.mcp.json` at the repo root — teammates who `composer install` get the MCP on pull (after approving it once) without re-running the installer. Manual registration:

```bash
claude mcp add --transport http --scope project watchtower https://<watchtower-host>/mcp/<project-id-or-slug> \
  --header 'Authorization: Bearer ${WATCHTOWER_TOKEN}'
```

The single quotes keep `${WATCHTOWER_TOKEN}` literal, so `.mcp.json` holds the variable name and Claude Code expands it from its environment at startup. **Never commit a token into `.mcp.json`**, and never use the DSN public key there: it is public, and the server accepts it for reads only during a deprecation window. If you actually split backend and browser into two Watchtower projects (rare — see `reference.md` § "When to split into two projects"), register one MCP server per project with distinct names (e.g. `watchtower-backend`, `watchtower-frontend`).

**Standalone / dual installs** serve the same tools from the app itself at `{APP_URL}/{prefix}/mcp` (default prefix `watchtower`), authenticated with the project's **MCP token**: `watchtower:install` mints one for the first project and prints it once; mint or replace one with `php artisan watchtower:project mcp-token <project>`. Export it as `WATCHTOWER_MCP_TOKEN`. Until `WATCHTOWER_MCP_LEGACY_KEYS=false` the server still accepts the public DSN key with a logged deprecation; set it false once `.mcp.json` sends the token. It needs `composer require laravel/mcp` — the package suggests rather than requires it, and `watchtower:doctor` says so when it's missing. Nine tools, all scoped to the one project whose token authenticated the request.

```bash
claude mcp add --transport http --scope project watchtower https://shop.test/watchtower/mcp \
  --header 'Authorization: Bearer ${WATCHTOWER_MCP_TOKEN}'
```

## MCP triage (when `mcp__watchtower__*` tools are connected)

- `mcp__watchtower__list_issues` / `mcp__watchtower__list_events` — browse, filter by environment / level / release / `since`.
- `mcp__watchtower__get_issue` — drill into one issue group. Use right after `list_issues` and grab `latest_event_id` from the response for the common "fix the latest occurrence" flow.
- `mcp__watchtower__get_event` — fetch a full event payload (stacktrace, breadcrumbs, request, contexts). The debugging entry point: feed the `event_id` from `get_issue.latest_event_id` (or from a verification capture) and read the stack to locate the bug.
- `mcp__watchtower__resolve_issue` / `ignore_issue` / `unresolve_issue` / `snooze_issue` — triage actions. Use after the user has confirmed a fix or noise classification, not unilaterally.
- `mcp__watchtower__get_stats` — volumes, top issues, status mix; useful for "what's noisy right now?" questions.

Full arg + return reference, plus the end-to-end verify and debug flows: `reference.md` § "Querying via MCP".

## Sweeping the production inbox

When asked to check or clean up production as a whole, not one issue, follow [`sweep.md`](sweep.md):
pull every unresolved production issue, read one event each (`jq` on the saved payload — full events
overrun the MCP output limit), sort each into probe bot / real bug / navigation race / transient upstream
/ link scanner / stale, then report a grouped table before touching any status.

Two habits it depends on: don't ignore probe-bot issues (resolve them once the block is deployed), and
don't trust `top_frame` on a PHP event — it is usually the outermost middleware, not the cause.
