---
name: watchtower-error-tracking
description: Use this skill when the user wants to wire up Watchtower (a self-hosted, Sentry-compatible exception tracker) into a new project, or connect Claude Code to Watchtower's MCP server for in-conversation issue triage. Covers Laravel backends end-to-end via the phattarachai/watchtower-laravel package (one command), browser JavaScript via @sentry/browser with the tunnel option, verifying ingestion through Watchtower's REST API, and adding the team-scoped MCP server so Claude can query and triage issues directly. Triggers on mentions of Watchtower, sentry-laravel, @sentry/browser, SENTRY_LARAVEL_DSN, VITE_SENTRY_DSN, WATCHTOWER_DSN, "set up error tracking", "wire up Watchtower", "verify the exception was reported", "add Watchtower MCP", "claude mcp add watchtower", or "triage Watchtower issues from Claude".
version: 2026.05.18
---

# Watchtower error tracking — install

Watchtower is a self-hosted, Sentry-compatible exception tracker. Client apps report errors through the standard Sentry SDKs pointed at a Watchtower instance.

**Scope of this skill:** install + configure + verify, end-to-end. Laravel backends, browser JavaScript frontends, or fullstack apps combining both. See [`reference.md`](reference.md) for full setup, browser-only configurations, troubleshooting, and REST endpoint reference.

## One-command install (Laravel — covers most cases)

```bash
composer require phattarachai/watchtower-laravel
php artisan watchtower:install
```

The install command prompts for the DSN, writes env keys, patches `bootstrap/app.php` to wire the Sentry exception handler (required for Laravel 11+, easy to forget), publishes the relay route at `/api/watchtower-relay`, and detects Vite to add `VITE_SENTRY_DSN` + `VITE_SENTRY_TUNNEL`. See `reference.md` for the full step list, `--dry-run`, and `--dsn=…` flags.

After install, paste the `Sentry.init({ ..., tunnel: import.meta.env.VITE_SENTRY_TUNNEL })` snippet into your JS entry file (snippet in `reference.md` § "Browser-side init"), then `npm install --save @sentry/browser && npm run build`.

Verify with `php artisan watchtower:test`.

## DSN must be numeric

Watchtower DSNs end in the project's **numeric id**, not its slug:

```
https://{public_key}@watchtower.phattarachai.app/42      ✅
https://{public_key}@watchtower.phattarachai.app/my-app  ❌
```

Stock Sentry SDKs silently reject non-numeric project segments at parse time — the SDK initializes but no events ever leave the client. The settings page renders the numeric form; copy verbatim. See `reference.md` § "DSN format" for the underlying cause.

## One Watchtower project per runtime

Don't share a project between PHP and JS. Public-key exposure (JS DSNs are visible in the bundle), fingerprinting noise, and alert tuning all push toward one-project-per-runtime. Typical fullstack split: `acme` (php-laravel) + `acme-web` (javascript). Create both in the Watchtower UI at `/projects/create`.

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
- **Read** — `list_issues`, `get_issue`, `list_events`, `get_event` (verification core).
- **Triage** — `resolve_issue`, `ignore_issue`, `unresolve_issue`, `snooze_issue`.

Full arg + return reference: `reference.md` § "Querying via MCP".

### MCP vs REST

- **MCP** — first choice when Claude is in the conversation. The model picks the right tool from descriptions and gets structured responses.
- **REST** (`curl /api/v1/...`) — better fit for CI scripts, post-deploy smoke tests, and any non-agent context. See `reference.md` § "Querying via REST".
