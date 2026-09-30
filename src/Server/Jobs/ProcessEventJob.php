<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerCore\Ingest\EventPipeline;
use Phattarachai\WatchtowerLaravel\Server\Alerts\AlertDispatcher;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueUser;
use Throwable;

/**
 * Safe to run more than once for the same event: a retry, a job restored from
 * a Redis snapshot, or two workers racing on one fingerprint all converge on a
 * single event row and a single group. Failures of this job are never
 * self-captured — see SelfCaptureGuard.
 */
class ProcessEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Unique-violation retries inside one attempt: the group insert race. */
    private const int PERSIST_ATTEMPTS = 3;

    public int $tries = 3;

    public int $timeout = 30;

    /** A hung attempt fails outright instead of resurfacing as MaxAttemptsExceededException. */
    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /**
     * @param  array<string, mixed>  $rawEvent
     */
    public function __construct(
        public readonly int $projectId,
        public readonly array $rawEvent,
        public readonly ?string $sdkName = null,
    ) {
        $this->onConnection(config('watchtower.server.queue.connection'));
        $this->onQueue(config('watchtower.server.queue.name'));
    }

    public function handle(): void
    {
        $pipeline = app(EventPipeline::class);
        $event = $pipeline->prepare($this->rawEvent);
        $eventId = $this->resolveEventId($event);

        if ($this->alreadyStored($eventId)) {
            return;
        }

        $result = $this->persist($pipeline, $event, $eventId);

        if ($result !== null) {
            $this->afterPersist($result);
        }
    }

    /**
     * A unique violation means another worker got there first: either with the
     * same event (done — return null) or with the first event of a new group
     * (retry, and the retry updates the group it created).
     *
     * @param  array<string, mixed>  $event
     * @return array{group: IssueGroup, event: Event, is_new_group: bool, is_regression: bool}|null
     */
    private function persist(EventPipeline $pipeline, array $event, string $eventId): ?array
    {
        $fingerprint = $pipeline->fingerprint($event);
        $title = $pipeline->title($event);
        $receivedAt = $this->resolveReceivedAt($event);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->connection()->transaction(
                    fn (): array => $this->write($event, $eventId, $fingerprint, $title, $receivedAt),
                );
            } catch (UniqueConstraintViolationException $e) {
                if ($this->alreadyStored($eventId)) {
                    return null;
                }

                if ($attempt >= self::PERSIST_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{group: IssueGroup, event: Event, is_new_group: bool, is_regression: bool}
     */
    private function write(array $event, string $eventId, string $fingerprint, string $title, Carbon $receivedAt): array
    {
        $upsert = $this->upsertGroup($event, $fingerprint, $title, $receivedAt);
        $group = $upsert['group'];
        $eventModel = $this->insertEvent($group, $event, $eventId, $receivedAt);
        $this->trackUniqueUser($group, $event, $receivedAt);

        return [
            'group' => $group,
            'event' => $eventModel,
            'is_new_group' => $upsert['is_new_group'],
            'is_regression' => $upsert['is_regression'],
        ];
    }

    private function alreadyStored(string $eventId): bool
    {
        return Event::query()->whereKey($eventId)->exists();
    }

    /**
     * Seam for downstream alert evaluation — persistence has committed by the
     * time this runs.
     *
     * @param  array{group: IssueGroup, event: Event, is_new_group: bool, is_regression: bool}  $result
     */
    protected function afterPersist(array $result): void
    {
        $group = $result['group'];

        $hasRules = AlertRule::where('project_id', $group->project_id)
            ->where('is_active', true)
            ->exists();

        if (! $hasRules) {
            return;
        }

        app(AlertDispatcher::class)->dispatch(
            $group,
            $result['event'],
            $result['is_new_group'],
            $result['is_regression'],
        );
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('watchtower.server.connection'));
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{group: IssueGroup, is_new_group: bool, is_regression: bool}
     */
    private function upsertGroup(array $event, string $fingerprint, string $title, Carbon $receivedAt): array
    {
        $group = IssueGroup::query()
            ->where('project_id', $this->projectId)
            ->where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first() ?? new IssueGroup(['project_id' => $this->projectId, 'fingerprint' => $fingerprint]);

        $isNew = ! $group->exists;
        $isRegression = ! $isNew && $this->isDormant($group);

        $group->title = $title;
        $group->platform = isset($event['platform']) ? (string) $event['platform'] : $group->platform;
        $group->level = (string) ($event['level'] ?? 'error');
        $group->last_seen_at = $receivedAt;
        $group->first_seen_at ??= $receivedAt;
        $group->event_count = ($group->event_count ?? 0) + 1;

        if ($isRegression) {
            $group->status = IssueGroup::STATUS_UNRESOLVED;
            $group->snoozed_until = null;
            $group->last_status_change_at = $receivedAt;
        }

        $group->save();

        return ['group' => $group, 'is_new_group' => $isNew, 'is_regression' => $isRegression];
    }

    /**
     * Resolved and ignored groups are dormant, as are snoozed groups whose
     * window has lapsed (or that were snoozed "until the next event") — a fresh
     * event on any of them is a regression. An active time-bound snooze is
     * honored so "shut up for an hour" really means an hour.
     */
    private function isDormant(IssueGroup $group): bool
    {
        return match ($group->status) {
            IssueGroup::STATUS_RESOLVED, IssueGroup::STATUS_IGNORED => true,
            IssueGroup::STATUS_SNOOZED => $group->snoozed_until === null || $group->snoozed_until->isPast(),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function insertEvent(IssueGroup $group, array $event, string $eventId, Carbon $receivedAt): Event
    {
        return Event::create([
            'id' => $eventId,
            'group_id' => $group->getKey(),
            'project_id' => $this->projectId,
            'environment' => isset($event['environment']) ? (string) $event['environment'] : null,
            'release' => isset($event['release']) ? (string) $event['release'] : null,
            'received_at' => $receivedAt,
            'level' => (string) ($event['level'] ?? 'error'),
            'sdk_name' => $this->sdkName ?? $this->sdkNameFromEvent($event),
            'user_id_hash' => $this->hashUser($event),
            'payload' => $event,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function trackUniqueUser(IssueGroup $group, array $event, Carbon $receivedAt): void
    {
        $hash = $this->hashUser($event);

        if ($hash === null) {
            return;
        }

        $created = IssueUser::firstOrCreate(
            ['group_id' => $group->getKey(), 'user_id_hash' => $hash],
            ['first_seen_at' => $receivedAt],
        )->wasRecentlyCreated;

        if ($created) {
            $group->increment('user_count');
        }
    }

    /**
     * The SDK sends `timestamp` as either an epoch float or an offset-carrying
     * ISO-8601 string. `received_at` is a naive `timestamp` column, so both forms
     * are normalised onto the host app's own timezone before storing — otherwise
     * the stored wall clock sits `app.timezone`'s offset away from `now()` and the
     * UI reads "just now" as e.g. "7h ago". Carbon's `createFromTimestamp()` pins
     * to UTC unless the zone is named, so it is passed explicitly.
     *
     * @param  array<string, mixed>  $event
     */
    private function resolveReceivedAt(array $event): Carbon
    {
        $timestamp = $event['timestamp'] ?? null;
        $timezone = (string) config('app.timezone');

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((float) $timestamp, $timezone);
        }

        if (is_string($timestamp) && $timestamp !== '') {
            try {
                return Carbon::parse($timestamp)->setTimezone($timezone);
            } catch (Throwable) {
                return Carbon::now();
            }
        }

        return Carbon::now();
    }

    /**
     * Sentry sends `event_id` as 32 hex chars without dashes — reshape it into
     * a UUID so it fits the uuid primary key.
     *
     * @param  array<string, mixed>  $event
     */
    private function resolveEventId(array $event): string
    {
        $id = (string) ($event['event_id'] ?? '');

        if ($id === '') {
            return (string) Str::uuid();
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $id) === 1) {
            return Str::lower(implode('-', [
                substr($id, 0, 8),
                substr($id, 8, 4),
                substr($id, 12, 4),
                substr($id, 16, 4),
                substr($id, 20, 12),
            ]));
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function sdkNameFromEvent(array $event): ?string
    {
        $name = $event['sdk']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function hashUser(array $event): ?string
    {
        $user = $event['user'] ?? null;

        if (! is_array($user)) {
            return null;
        }

        $key = $user['id'] ?? $user['email'] ?? $user['ip_address'] ?? null;

        if ($key === null || $key === '') {
            return null;
        }

        return hash('sha256', (string) $key);
    }
}
