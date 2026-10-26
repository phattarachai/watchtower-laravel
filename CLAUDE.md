# Watchtower Laravel — agent guide

## Scope

Sentry-compatible error tracking for Laravel. The public story is **standalone mode**: the host app owns the `watchtower_*` tables, the ingest endpoint, the issue UI and the MCP server. Relay and dual report to a central Watchtower server; that server's repo is private, so never link it from anything shipped (README, the Boost skill and guidelines, this file).

`inertiajs/inertia-laravel` is only suggested. Everything that touches it stays behind `UiPage::available()`, so a relay-only or headless Livewire/Filament host never needs it.

## Install path

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install --dsn=http://{key}@{host}/{numeric-project-id}
```

The install command is idempotent. Pass `--dry-run` to preview.

It will:

1. Validate the DSN (project segment must be numeric — stock Sentry SDKs reject non-numeric).
2. Write `WATCHTOWER_DSN` + `SENTRY_LARAVEL_DSN` to `.env`.
3. Patch `bootstrap/app.php` so `Integration::handles($exceptions)` runs inside `withExceptions(...)`.
4. Publish `config/watchtower.php`.
5. If a Vite config exists, set `VITE_SENTRY_DSN` (same value as `SENTRY_LARAVEL_DSN`), `VITE_SENTRY_TUNNEL=/api/watchtower-relay`, and `VITE_SENTRY_ENVIRONMENT=${APP_ENV}`. To split backend and frontend into two Watchtower projects, edit `VITE_SENTRY_DSN` in `.env` after install — see the skill's `reference.md` § "When to split into two projects".
6. If `claude` (Claude Code CLI) is on PATH, register the Watchtower HTTP MCP server with `--scope project` (writes a committed `.mcp.json` so teammates get the MCP on `composer install`, not just the machine that ran install). The bearer is the literal `${WATCHTOWER_TOKEN}` (relay: the user's PAT, URL `/mcp/{project_id}`) or `${WATCHTOWER_MCP_TOKEN}` (embedded: a per-project MCP token the install mints once) — never the public DSN key, which ships to browsers. Pass `--no-mcp` to skip.

## Browser init

Paste into your Vite entry (e.g. `resources/js/app.js`):

```js
import * as Sentry from '@sentry/browser';

Sentry.init({
    dsn: import.meta.env.VITE_SENTRY_DSN,
    tunnel: import.meta.env.VITE_SENTRY_TUNNEL,
    environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
});
```

## Browser-side Livewire rules

`resources/js/livewire.js` holds the `beforeSend` hook `watchtower.js` imports: it drops Livewire's
transient noise (419, cancelled `wire:navigate`, torn-down component, all-null response) and restates
its genuine failures — which Livewire rejects as a raw `{status, body, json, errors}` object rather
than an `Error` — as `LivewireRequestFailed: … HTTP <status>`, fingerprinted by status. Without that
restatement every server failure collapses into one `Object captured as exception with keys: …` issue
whose stack is the minified SDK, so it reads as if Watchtower itself were crashing.

`resources/js/noise.js` runs before it: it drops events whose `navigator.userAgent` is a crawler and
fingerprints every chunk-load failure as `['chunk-load-error']`. Core applies the same two rules at ingest
(`BotDetector`, `Fingerprinter`), so keep its `BOT_USER_AGENT` in step with core's.

All three files publish under the `watchtower-js` tag. `livewire.js` and `noise.js` are deliberately
import-free so they can be unit-tested without a bundler or npm install; `noise.js` takes the user agent
as an argument.

## Core first

Ingest logic is shared with the central Watchtower server (`/Users/phatchai/www/watchtower`) through
[`phattarachai/watchtower-core`](https://github.com/phattarachai/watchtower-core) (sibling checkout at
`/Users/phatchai/www/packages/watchtower-core`). Before changing how an event is parsed, decoded, scrubbed,
normalized, trimmed or fingerprinted, make the change in core so the server gets it too. This package keeps only the
Laravel wiring: config → constructor arguments, queue/cache/DB, routes, the Sentry SDK hooks.

- Core is framework-free: no `illuminate/*`, no `config()`. Everything arrives through constructor arguments.
- Order: core PR → core release (a tag and a GitHub release, which pings Packagist) → bump `phattarachai/watchtower-core`
  here → then update the central server in the same way.
- If a fix can't wait for a core release, ship it here and open a follow-up to move it into core. Don't leave a
  permanent fork of core logic in this package.

Laravel-specific safeguards stay here: `SelfCaptureGuard` (queue events), `IngestThrottle` (RateLimiter), queue
backpressure, and `watchtower:doctor`.

## Tests

```bash
composer test       # both suites
composer test:php   # vendor/bin/pest
composer test:js    # node --test tests/js/*.test.mjs — no npm dependency, Node's built-in runner
```

## Verify

```bash
php artisan watchtower:test
```

Then check the issues page on the upstream Watchtower instance.

## Canonical skill

The package ships the canonical Claude skill at `resources/boost/skills/watchtower-error-tracking/`. Laravel Boost auto-discovers it on `php artisan boost:install --skills`.

When editing the skill, keep `SKILL.md` and `reference.md` in sync with `src/Console/InstallCommand.php` (especially the MCP registration behavior).
