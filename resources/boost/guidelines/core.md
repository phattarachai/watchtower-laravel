# Watchtower (error tracking)

Watchtower is a self-hosted, Sentry-compatible exception tracker. This package wires the project's Laravel backend + browser frontend to it and exposes a MCP server so Claude can query and triage issues directly. For install, wiring details, triage, verification, and troubleshooting, use the `watchtower-error-tracking` skill.

In standalone/dual mode on Redis + Horizon, `ProcessEventJob` belongs on a dedicated `watchtower` queue with its own Horizon supervisor (add the supervisor before setting `WATCHTOWER_QUEUE_NAME=watchtower`), and Redis needs a `maxmemory` cap. If Redis memory balloons with `ProcessEventJob` payloads, follow the skill's "Self-capture loop — triage" runbook before touching anything else.
