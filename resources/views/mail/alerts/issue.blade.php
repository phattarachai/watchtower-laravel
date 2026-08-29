<x-mail::message>
@if ($isTest)
> **Test email** — sent from the alert rules screen of this project, not a real alert.

@endif
**Project:** {{ $project->name }}{{ $event->environment ? ' · '.$event->environment : '' }}
**Level:** {{ strtoupper($event->level) }}{{ $event->release ? ' · release '.$event->release : '' }}

{{ $headline }}

---

## {{ $issue->title }}

@if ($topFrame)
**Top frame:**

```
{{ $topFrame['file'] }}:{{ $topFrame['line'] }}
{{ $topFrame['function'] }}
```
@endif

| Field | Value |
|-------|-------|
| First seen | {{ $firstSeenAt?->toDayDateTimeString() }} |
| Last seen | {{ $lastSeenAt?->toDayDateTimeString() }} |
| Total events | {{ $issue->eventCount }} |
| Affected users | {{ $issue->userCount }} |
| Fingerprint | `{{ $issue->fingerprint }}` |

<x-mail::button :url="$issueUrl" color="error">
View issue in Watchtower
</x-mail::button>

<small>
Sent by rule <strong>{{ $rule->name }}</strong>{{ $rule->cooldownSeconds ? ' · cooldown '.$rule->cooldownSeconds.'s' : '' }}
</small>

— {{ config('app.name') }}
</x-mail::message>
