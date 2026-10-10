# Changelog

All notable changes to `phattarachai/watchtower-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/watchtower-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## Upgrade notes

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
