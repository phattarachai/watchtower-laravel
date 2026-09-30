# Changelog

All notable changes to `phattarachai/watchtower-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/watchtower-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## Upgrade notes

### 1.2.0

- **Move `ProcessEventJob` to a dedicated queue** on Redis/Horizon hosts: add a Horizon supervisor for `watchtower`, deploy,
  then set `WATCHTOWER_QUEUE_NAME=watchtower`. `watchtower:doctor` now fails when no supervisor consumes the configured
  queue. The default is unchanged (the host's default queue), so upgrading strands nothing.
- Cap Redis with `maxmemory` + `maxmemory-policy noeviction`.
- Behaviour changes, all opt-out via env: every ingest path is rate-limited per project and per fingerprint
  (`WATCHTOWER_RATE_LIMIT_PER_FINGERPRINT_PER_MIN`, default 20 — over-budget events are counted on their group, not
  stored); events are trimmed to `WATCHTOWER_MAX_EVENT_BYTES` (200 KB) before queueing; relay forwards are gzipped
  (`WATCHTOWER_FORWARD_GZIP`); Watchtower's own queue failures are never self-captured.
