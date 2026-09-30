<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Phattarachai\WatchtowerCore\Ingest\EventPipeline;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

beforeEach(function (): void {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.connections.database.connection', 'testing');
    config()->set('watchtower.server.queue.connection', 'database');
    config()->set('watchtower.server.max_queue_depth', 2);
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 0);
});

/** @return array<string, mixed> */
function eventOfType(string $type): array
{
    return ['exception' => ['values' => [['type' => $type, 'value' => $type]]]];
}

function ingestDistinct(Project $project, string $type): void
{
    $accepter = app(EnvelopeAccepter::class);
    $accepter->ingest($project, $accepter->parse(SentryEnvelope::build(eventOfType($type))));
}

function fingerprintOf(string $type): string
{
    $pipeline = app(EventPipeline::class);

    return $pipeline->fingerprint($pipeline->prepare(SentryEnvelope::eventPayload(eventOfType($type))));
}

it('stops queueing once the Watchtower queue is at its depth cap', function (): void {
    $project = makeWatchtowerProject();

    ingestDistinct($project, 'FirstError');
    ingestDistinct($project, 'SecondError');
    $this->travel(6)->seconds();
    ingestDistinct($project, 'ThirdError');

    expect(DB::table('jobs')->count())->toBe(2);
});

it('counts an event it could not queue on its existing group', function (): void {
    $project = makeWatchtowerProject();
    ingestDistinct($project, 'FirstError');
    ingestDistinct($project, 'SecondError');
    $this->travel(6)->seconds();

    $group = makeWatchtowerGroup($project, ['fingerprint' => fingerprintOf('FirstError'), 'event_count' => 7]);

    ingestDistinct($project, 'FirstError');

    expect(DB::table('jobs')->count())->toBe(2)
        ->and(IssueGroup::query()->findOrFail($group->id)->event_count)->toBe(8);
});

it('re-reads the depth only every few seconds', function (): void {
    $project = makeWatchtowerProject();

    foreach (['A', 'B', 'C', 'D'] as $type) {
        ingestDistinct($project, "{$type}Error");
    }

    expect(DB::table('jobs')->count())->toBe(4);
});

it('is disabled by a zero cap', function (): void {
    config()->set('watchtower.server.max_queue_depth', 0);
    $project = makeWatchtowerProject();

    foreach (['A', 'B', 'C'] as $type) {
        ingestDistinct($project, "{$type}Error");
    }

    expect(DB::table('jobs')->count())->toBe(3);
});

it('never applies to the sync queue', function (): void {
    config()->set('watchtower.server.queue.connection', 'sync');
    config()->set('watchtower.server.max_queue_depth', 1);
    $project = makeWatchtowerProject();

    ingestDistinct($project, 'AError');
    ingestDistinct($project, 'BError');

    expect(IssueGroup::query()->count())->toBe(2);
});
