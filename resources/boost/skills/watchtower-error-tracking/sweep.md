# Sweeping a production inbox

The flow for "check / sweep / clean up the production issues": read every open issue, sort it into a
bucket, act per bucket, and report before changing any status.

- [1. Pull the inbox](#1-pull-the-inbox)
- [2. Read one event per issue](#2-read-one-event-per-issue)
- [3. Classify](#3-classify)
- [4. Probe bots](#4-probe-bots)
- [5. Report, then act](#5-report-then-act)

## 1. Pull the inbox

`get_stats(window: "7d")` for the shape, then `list_issues(environment: "production", status: "unresolved")`,
paging until `meta.last_page`. Keep `user_count`, `event_count` and `last_seen_at` per issue — they drive
the classification as much as the title does.

## 2. Read one event per issue

`get_issue` → `latest_event_id` → `get_event`. A full event is often 60–80K characters, past the MCP
output limit, so the client saves it to a file. Read it with `jq` instead of paging through it:

```bash
jq '.event | {url: .request.url, method: .request.method,
  ip: (.request.headers["cf-connecting-ip"] // .request.headers["x-real-ip"])[0],
  ua: .request.headers["user-agent"][0],
  body: (.request.data | tostring | .[0:400])}' <saved-file>
```

With more than a handful of issues, hand the reading to a subagent and keep only its table.

- **`top_frame` on a PHP event is often the outermost app middleware**, the frame wrapping the whole
  pipeline, not the cause. Read the `in_app` frames of `stacktrace`.
- **A browser event's cause is in its breadcrumbs.** The console breadcrumb carries the failing Alpine
  expression; the navigation and fetch breadcrumbs before it show the race.
- **The client IP lives in the CDN's header** (`cf-connecting-ip` on Cloudflare). `x-forwarded-for` is
  scrubbed by default.

## 3. Classify

| Bucket | Tells | Action |
|---|---|---|
| Probe bot | 0 users; `POST …/livewire…/update` with `X-Livewire`; `CannotUpdateLockedPropertyException`, a `TypeError` in `Filament\Notifications\Collection::fromLivewire`, or `MissingFileUploadsTraitException` for a `.php` upload; a body holding `O:<n>:"` (a PHPGGC gadget chain) or a shell chain; one or two IPs | §4 |
| Real bug | users > 0, an `in_app` frame in app code | Reproduce in a failing test, fix, resolve after the deploy |
| Navigation race | Browser `ReferenceError` thrown from `livewire.min.js`, mechanism `setTimeout`, a navigation breadcrumb just before | Add the framework's identifiers to the browser `beforeSend` allowlist, only when no frame is from the app bundle. Don't patch components |
| Transient upstream | One `ConnectionException` / timeout to a third-party API, one SMTP blip | Snooze `until_event`; ignore if long past. Recurring → a real bug (timeouts, retries) |
| Link scanner | `Object Not Found Matching Id:N, MethodName:update` — a mail scanner (e.g. Outlook Safe Links) replaying the page | Ignore |
| Stale | Not seen for months, no repro | Ask |

## 4. Probe bots

Livewire refuses the hydration, so nothing executes. The cost is a request cycle and the noise.

- **Don't ignore these issues.** They are the only visibility into who is probing. Resolve them once the
  block is deployed, so a new variant re-opens one.
- **Find why the app let it through.** If the host has a payload firewall middleware, test the event body
  against its signatures. The usual gap: a gadget chain carries no shell substring (`passthru` appears
  without `(`). Matching the serialized-object literal `O:\d+:\\{0,2}"` catches it, and legitimate
  Livewire JSON never contains one.
- **Collect the IPs from the events and ban them with the host's own tooling.** This package ships no
  server config. Behind a CDN the origin's packet filter only ever sees the edge's IP, so a firewall ban
  never matches the client: ban at the web server, from the address its real-IP module restored, or at
  the CDN.

## 5. Report, then act

Report one table grouped by bucket — issue id, title, one line of evidence, proposed action — and ask
before any `resolve_issue` / `ignore_issue` / `snooze_issue`. Resolve a fixed issue only once the fix is
deployed and CI is green.

If events carry `release: null`, a resolved issue can't re-open on a newer release. Set
`SENTRY_RELEASE` to the deployed commit in the deploy step so regressions surface.
