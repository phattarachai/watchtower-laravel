<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ui;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Support\ClaudeIssuePrompt;
use Phattarachai\WatchtowerLaravel\Server\Support\EventOrigin;

final class IssueDetailPresenter
{
    public const int RECENT_EVENTS = 25;

    /**
     * @return array<string, mixed>
     */
    public static function props(Request $request, IssueGroup $group): array
    {
        $event = self::resolveEvent($request, $group);

        return [
            'issue' => self::issue($group),
            'event' => $event === null ? null : self::event($event),
            'events' => self::recentEvents($group),
            'navigation' => $event === null ? self::emptyNavigation() : self::navigation($group, $event),
            'markdown' => self::markdown($request, $group, $event),
        ];
    }

    /**
     * The self-contained Claude prompt copied by the "Copy Markdown" button. The
     * permalink is the canonical issue URL (query string dropped) so it points at
     * the issue rather than the currently-selected event.
     */
    private static function markdown(Request $request, IssueGroup $group, ?Event $event): string
    {
        return (new ClaudeIssuePrompt(new EventOrigin))->build(
            $group->project,
            $group,
            $event,
            $request->url(),
        );
    }

    /**
     * A `?event=` that does not belong to this group is a 404, not a silent
     * fallback to the latest one.
     */
    private static function resolveEvent(Request $request, IssueGroup $group): ?Event
    {
        $requested = $request->query('event');

        if (is_string($requested) && $requested !== '') {
            $event = Event::query()->where('group_id', $group->getKey())->find($requested);

            abort_if($event === null, 404);

            return $event;
        }

        return Event::query()
            ->where('group_id', $group->getKey())
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function issue(IssueGroup $group): array
    {
        return [
            ...IssueListPresenter::row($group),
            'fingerprint' => (string) $group->fingerprint,
            'resolved_in_release' => $group->resolved_in_release,
            'last_status_change_at' => $group->last_status_change_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(Event $event): array
    {
        $payload = (array) ($event->payload ?? []);

        return [
            'id' => (string) $event->getKey(),
            'event_id' => data_get($payload, 'event_id'),
            'received_at' => $event->received_at->toIso8601String(),
            'timestamp' => data_get($payload, 'timestamp'),
            'level' => (string) $event->level,
            'environment' => $event->environment,
            'release' => $event->release,
            'sdk_name' => $event->sdk_name,
            'platform' => data_get($payload, 'platform'),
            'transaction' => data_get($payload, 'transaction'),
            'server_name' => data_get($payload, 'server_name'),
            'message' => self::message($payload),
            'exception' => self::exception($payload),
            'mechanism' => data_get($payload, 'exception.values.0.mechanism'),
            'stacktrace' => self::listOf(data_get($payload, 'exception.values.0.stacktrace.frames')),
            'request' => self::arrayOrNull(data_get($payload, 'request')),
            'breadcrumbs' => self::breadcrumbs($payload),
            'user' => self::arrayOrNull(data_get($payload, 'user')),
            'tags' => self::arrayOrNull(data_get($payload, 'tags')),
            'contexts' => self::arrayOrNull(data_get($payload, 'contexts')),
            'extra' => self::arrayOrNull(data_get($payload, 'extra')),
            'sdk' => self::arrayOrNull(data_get($payload, 'sdk')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recentEvents(IssueGroup $group): array
    {
        return Event::query()
            ->where('group_id', $group->getKey())
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_EVENTS)
            ->get()
            ->map(fn (Event $event): array => [
                'id' => (string) $event->getKey(),
                'received_at' => $event->received_at->toIso8601String(),
                'environment' => $event->environment,
                'release' => $event->release,
                'level' => (string) $event->level,
            ])
            ->all();
    }

    /**
     * `prev` walks towards newer events, `next` towards older ones — the list
     * itself is newest first.
     *
     * @return array{prev: string|null, next: string|null, position: int, total: int}
     */
    private static function navigation(IssueGroup $group, Event $event): array
    {
        $newer = self::neighbour($group, $event, newer: true);
        $older = self::neighbour($group, $event, newer: false);

        $total = Event::query()->where('group_id', $group->getKey())->count();

        $ahead = Event::query()
            ->where('group_id', $group->getKey())
            ->where('received_at', '>', $event->received_at)
            ->count();

        return [
            'prev' => $newer,
            'next' => $older,
            'position' => $ahead + 1,
            'total' => $total,
        ];
    }

    private static function neighbour(IssueGroup $group, Event $event, bool $newer): ?string
    {
        $found = Event::query()
            ->where('group_id', $group->getKey())
            ->whereKeyNot($event->getKey())
            ->when(
                $newer,
                fn (Builder $query) => $query->where('received_at', '>=', $event->received_at)->orderBy('received_at'),
                fn (Builder $query) => $query->where('received_at', '<=', $event->received_at)->orderByDesc('received_at'),
            )
            ->first();

        return $found === null ? null : (string) $found->getKey();
    }

    /**
     * @return array{prev: null, next: null, position: int, total: int}
     */
    private static function emptyNavigation(): array
    {
        return ['prev' => null, 'next' => null, 'position' => 0, 'total' => 0];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function message(array $payload): ?string
    {
        $message = data_get($payload, 'message');

        if (is_string($message)) {
            return $message;
        }

        $formatted = data_get($payload, 'message.formatted', data_get($payload, 'message.message'));

        return is_string($formatted) ? $formatted : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string|null, value: string|null, module: string|null}|null
     */
    private static function exception(array $payload): ?array
    {
        $first = data_get($payload, 'exception.values.0');

        if (! is_array($first)) {
            return null;
        }

        return [
            'type' => is_string($first['type'] ?? null) ? $first['type'] : null,
            'value' => is_string($first['value'] ?? null) ? $first['value'] : null,
            'module' => is_string($first['module'] ?? null) ? $first['module'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function breadcrumbs(array $payload): array
    {
        return self::listOf(data_get($payload, 'breadcrumbs.values', data_get($payload, 'breadcrumbs')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
}
