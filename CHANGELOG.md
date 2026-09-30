# Changelog

All notable changes to `phattarachai/watchtower-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/watchtower-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## Upgrade notes

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
