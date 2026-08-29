<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerCore\Ingest\EventNormalizer;
use Phattarachai\WatchtowerCore\Ingest\EventScrubber;
use Phattarachai\WatchtowerCore\Ingest\Fingerprinter;
use Phattarachai\WatchtowerCore\Ingest\MessageNormalizer;
use Phattarachai\WatchtowerLaravel\Server\Alerts\AlertDispatcher;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueUser;
use Throwable;

class ProcessEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

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
        $fingerprinter = new Fingerprinter(new MessageNormalizer);
        $event = $this->normalizer()->normalize($this->scrubber()->scrub($this->rawEvent));
        $fingerprint = $fingerprinter->compute($event);
        $receivedAt = $this->resolveReceivedAt($event);

        $result = $this->connection()->transaction(function () use ($event, $fingerprint, $receivedAt, $fingerprinter): array {
            $upsert = $this->upsertGroup($event, $fingerprint, $receivedAt, $fingerprinter);
            $group = $upsert['group'];
            $eventModel = $this->insertEvent($group, $event, $receivedAt);
            $this->trackUniqueUser($group, $event, $receivedAt);

            return [
                'group' => $group,
                'event' => $eventModel,
                'is_new_group' => $upsert['is_new_group'],
                'is_regression' => $upsert['is_regression'],
            ];
        });

        $this->afterPersist($result);
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

    private function scrubber(): EventScrubber
    {
        return new EventScrubber(
            headerKeys: (array) config('watchtower.server.ingest.scrub.header_keys', []),
            bodyKeys: (array) config('watchtower.server.ingest.scrub.body_keys', []),
            placeholder: (string) config('watchtower.server.ingest.scrub.placeholder', '[Filtered]'),
        );
    }

    private function normalizer(): EventNormalizer
    {
        return new EventNormalizer(
            allowedEventFields: (array) config('watchtower.server.ingest.allowed_event_fields', []),
            allowedContextKeys: (array) config('watchtower.server.ingest.allowed_context_keys', []),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{group: IssueGroup, is_new_group: bool, is_regression: bool}
     */
    private function upsertGroup(
        array $event,
        string $fingerprint,
        Carbon $receivedAt,
        Fingerprinter $fingerprinter,
    ): array {
        $group = IssueGroup::firstOrNew([
            'project_id' => $this->projectId,
            'fingerprint' => $fingerprint,
        ]);

        $isNew = ! $group->exists;
        $isRegression = ! $isNew && $this->isDormant($group);

        $group->title = $fingerprinter->title($event);
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
    private function insertEvent(IssueGroup $group, array $event, Carbon $receivedAt): Event
    {
        return Event::create([
            'id' => $this->resolveEventId($event),
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
     * @param  array<string, mixed>  $event
     */
    private function resolveReceivedAt(array $event): Carbon
    {
        $timestamp = $event['timestamp'] ?? null;

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((float) $timestamp);
        }

        if (is_string($timestamp) && $timestamp !== '') {
            try {
                return Carbon::parse($timestamp);
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
