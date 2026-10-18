# Changelog

All notable changes to `phattarachai/watchtower-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/watchtower-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## Upgrade notes

### 1.6.0

- Requires `phattarachai/watchtower-core` ^1.3.
- **Crawler browser events are dropped.** The browser helper drops events whose `navigator.userAgent` is a crawler
  (Googlebot, GoogleOther, bingbot, AdsBot, …), and standalone/dual ingest drops them server-side too
  (`WATCHTOWER_DROP_BOT_EVENTS`, default `true`). Server-side events and HeadlessChrome browser tests still report.
- **Chunk-load failures share one issue.** `Failed to fetch dynamically imported module: …/Show-<hash>.js` and the
  Safari, Firefox and Vite CSS equivalents now group as `chunk-load-error` instead of one issue per hash. Resolve the
  old per-hash issues; they stop receiving events.
- **Re-publish the browser helper** to pick up the SDK-side rules: `php artisan vendor:publish --tag=watchtower-js --force`.
  It now writes a third file, `resources/js/vendor/noise.js`. Ingest covers old clients in the meantime.

### 1.5.2

- **`watchtower:doctor` reads the Horizon plan the way Horizon runs it.** A supervisor for the Watchtower queue defined
  only under `horizon.defaults` runs in every environment, because Horizon merges `defaults` into each one. The doctor
  only counted supervisors also listed under the environment, so it failed "Horizon listens on [watchtower]" while the
  supervisor was running. It now merges `defaults` into the first environment key that matches `APP_ENV`, `*` and other
  patterns included, and skips a supervisor scaled to `maxProcesses` 0. When no environment key matches, Horizon starts
  nothing, so the check still fails.

### 1.5.1

- **`WATCHTOWER_UI_THEME` works with a config published by 1.4 or earlier.** Laravel's `mergeConfigFrom` only fills
  missing top-level keys, so a published `server` block hid `server.ui.theme` and the setting did nothing until the
  config was re-published. The package now fills missing keys at any depth from its defaults, including under
  `config:cache`. Lists you published (scrub keys, ignored exceptions, middleware) are kept as written, not merged.
  The 1.5 scrubbing changes already applied to an old config, and still do.
- **Re-publishing the page respects a TypeScript app.** `vendor:publish --tag=watchtower-inertia --force` now writes
  `resources/js/pages/Watchtower.tsx` when the app is TypeScript (`tsconfig.json`, `resources/js/app.tsx` or an
  existing `Watchtower.tsx`), the same detection `watchtower:install` uses. On 1.5.0 it wrote `Watchtower.jsx` beside
  the `.tsx`, and the app kept loading the old `.tsx`. If you re-published on 1.5.0 in a TypeScript app, delete
  `resources/js/pages/Watchtower.jsx` and run the publish command again. `watchtower:doctor` now warns when both files
  exist.

### 1.5.0

- Requires `phattarachai/watchtower-core` ^1.2. **Issue titles read better, and grouping doesn't change.** A bare date shows
  as `<DATE>` (it was `<N>-10-04`), and a pure decimal such as a memory size shows as `<N>` (it was `<HASH>`). The fingerprint
  keeps its old rules, so no stored issue regroups. Existing issues pick up the new title on their next event.
- **Scrubbing.** `X-XSRF-TOKEN` (which Inertia and axios send on every request), `X-CSRF-TOKEN` and a form's `_token` are now
  always scrubbed, by the SDK filter and by ingest alike, even when your published `config/watchtower.php` predates them.
  The Sentry SDK filters only `Authorization` and `Cookie`. With `SENTRY_SEND_DEFAULT_PII=true`, every cookie value is now
  `[Filtered]` (the names are kept), the session and remember-me cookies included.
- **Chained exceptions.** The Stacktrace tab, the alert email, the MCP `list_events` tool and the Copy Markdown prompt now
  show the exception that was thrown (the one the title names), not its innermost cause. The causes are listed below it
  as "Caused by" sections. Sentry orders `exception.values` oldest first.
- **Settings.** The key is masked in the DSN and every install snippet as well as the key row; **Reveal key** shows it,
  and Copy always copies the real value. The Laravel snippet now says `SENTRY_SEND_DEFAULT_PII=false`, matching the
  installer default.
- New `WATCHTOWER_UI_THEME` (`auto` | `light` | `dark`, default `auto`). `auto` keeps the current behaviour: dark under the
  host's `.dark` class.
- `watchtower:install` finds the Inertia root view (a top-level `resources/views/*.blade.php` rendering `@inertia`, such
  as the React starter kit's `app.blade.php`) when placing `@watchtowerUser`.
- The published page stub no longer reads `document` during SSR. If you published `resources/js/pages/Watchtower.jsx` (or `.tsx`)
  earlier and run Inertia SSR, re-publish it (`php artisan vendor:publish --tag=watchtower-inertia --force`) or guard the
  `document.querySelector` line with `typeof document === 'undefined'`.

### 1.4.0

- `inertiajs/inertia-laravel` moved from `require` to `suggest`, so relay-only and headless Livewire, Filament or Blade
  apps no longer install it. Nothing that worked stops working, but if your app uses the standalone issue UI and never
  required Inertia itself (it only came in through this package), run `composer require inertiajs/inertia-laravel`
  before your next `composer update`. Without it the UI routes aren't registered, and `watchtower:doctor` says so.
- `watchtower:install --standalone` without Inertia skips the page stub, Vite alias and `watchtower.css`, and says how
  to add the UI later.
- `watchtower:doctor` treats `WATCHTOWER_UI_ENABLED=false` as a headless install (advisory, not a failure) and skips
  the page, alias and Tailwind checks for it.

### 1.3.0

- Requires `phattarachai/watchtower-core` ^1.1 — the event pipeline (scrub → normalize → trim) and the gzip inflate cap now
  come from core, shared with the central server.
- New defaults, opt-out via env: `WATCHTOWER_MAX_QUEUE_DEPTH=5000` (past it, events are counted rather than queued; `0`
  disables) and `WATCHTOWER_REDACT_SQL_VALUES=true` (SQL error messages lose their inlined row values; breadcrumb
  `bindings` are dropped). Breadcrumb `data` is now key-scrubbed like request data.
- Alert mail (`IssueAlertMail`) is queued on `WATCHTOWER_QUEUE_CONNECTION` / `WATCHTOWER_QUEUE_NAME` instead of the
  app's default queue, so the Watchtower supervisor must be running for alerts to go out.
- `watchtower:doctor` warns when the queue's Redis has no `maxmemory` or an evicting policy.

### 1.2.0

- **Move `ProcessEventJob` to a dedicated queue** on Redis/Horizon hosts: add a Horizon supervisor for `watchtower`, deploy,
  then set `WATCHTOWER_QUEUE_NAME=watchtower`. `watchtower:doctor` now fails when no supervisor consumes the configured
  queue. The default is unchanged (the host's default queue), so upgrading strands nothing.
- Cap Redis with `maxmemory` + `maxmemory-policy noeviction`.
- Behaviour changes, all opt-out via env: every ingest path is rate-limited per project and per fingerprint
  (`WATCHTOWER_RATE_LIMIT_PER_FINGERPRINT_PER_MIN`, default 20 — over-budget events are counted on their group, not
  stored); events are trimmed to `WATCHTOWER_MAX_EVENT_BYTES` (200 KB) before queueing; relay forwards are gzipped
  (`WATCHTOWER_FORWARD_GZIP`); Watchtower's own queue failures are never self-captured.
