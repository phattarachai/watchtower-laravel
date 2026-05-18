# Watchtower (error tracking)

Watchtower is a self-hosted, Sentry-compatible exception tracker. This package wires the project's Laravel backend + browser frontend to it and exposes a MCP server so Claude can query and triage issues directly.

## MCP triage (when `mcp__watchtower__*` tools are connected)

- `mcp__watchtower__get_issue` — drill into one issue group. Use right after `list_issues` and grab `latest_event_id` from the response for the common "fix the latest occurrence" flow.
- `mcp__watchtower__get_event` — fetch a full event payload (stacktrace, breadcrumbs, request, contexts). The debugging entry point: feed the `event_id` from `get_issue.latest_event_id` (or from a verification capture) and read the stack to locate the bug.
- `mcp__watchtower__list_issues` / `mcp__watchtower__list_events` — browse, filter by project / environment / level / release / `since`.
- `mcp__watchtower__resolve_issue` / `ignore_issue` / `unresolve_issue` / `snooze_issue` — triage actions. Use after the user has confirmed a fix or noise classification, not unilaterally.
- `mcp__watchtower__get_stats` / `get_team` — volumes, top issues, status mix; useful for "what's noisy right now?" questions.
