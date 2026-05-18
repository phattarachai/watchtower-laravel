# Watchtower Laravel — agent guide

## Scope

This package is the Laravel-side wiring + browser-side tunnel proxy for [Watchtower](https://github.com/phattarachai/watchtower), a self-hosted Sentry-compatible exception tracker. It installs `sentry/sentry-laravel`, wires it into `bootstrap/app.php`, and registers `/api/watchtower-relay` so the browser SDK can post envelopes same-origin (ad-blocker safe).

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
5. If a Vite config exists, set `VITE_SENTRY_DSN`, `VITE_SENTRY_TUNNEL=/api/watchtower-relay`, and `VITE_SENTRY_ENVIRONMENT=${APP_ENV}`.
6. If `claude` (Claude Code CLI) is on PATH, register the Watchtower HTTP MCP server. Pass `--no-mcp` to skip.

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

## Verify

```bash
php artisan watchtower:test
```

Then check the issues page on the upstream Watchtower instance.

## Canonical skill

The package ships the canonical Claude skill at `resources/boost/skills/watchtower-error-tracking/`. Laravel Boost auto-discovers it on `php artisan boost:install --skills`.

When editing the skill, keep `SKILL.md` and `reference.md` in sync with `src/Console/InstallCommand.php` (especially the MCP registration behavior).
