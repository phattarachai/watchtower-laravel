<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Ingest\EventTruncator;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

it('stores an event once when the same job runs twice', function (): void {
    $job = new ProcessEventJob(makeWatchtowerProject()->id, SentryEnvelope::eventPayload());

    $job->handle();
    $job->handle();

    expect(Event::query()->count())->toBe(1)
        ->and(IssueGroup::query()->sole()->event_count)->toBe(1);
});

it('retries the write when another worker creates the group first', function (): void {
    $project = makeWatchtowerProject();
    $raced = false;

    IssueGroup::creating(function (IssueGroup $group) use (&$raced): void {
        if ($raced) {
            return;
        }

        $raced = true;
        DB::table('watchtower_issue_groups')->insert([
            'project_id' => $group->project_id,
            'fingerprint' => $group->fingerprint,
            'title' => 'created by the other worker',
            'event_count' => 1,
        ]);
    });

    new ProcessEventJob($project->id, SentryEnvelope::eventPayload())->handle();

    expect($raced)->toBeTrue()
        ->and(IssueGroup::query()->count())->toBe(1)
        ->and(Event::query()->count())->toBe(1);
});

it('fails a hung attempt outright and backs off between retries', function (): void {
    $job = new ProcessEventJob(1, []);

    expect($job->failOnTimeout)->toBeTrue()
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 30]);
});

it('queues a bounded, already-scrubbed payload', function (): void {
    Queue::fake();
    $project = makeWatchtowerProject();
    $event = SentryEnvelope::eventPayload([
        'message' => str_repeat('previous job payload ', 150_000),
        'request' => ['headers' => ['cookie' => 'laravel_session=secret']],
        'modules' => ['laravel/framework' => '12.0.0'],
    ]);

    $accepter = app(EnvelopeAccepter::class);
    $accepter->ingest($project, $accepter->parse(SentryEnvelope::build($event)));

    Queue::assertPushed(ProcessEventJob::class, function (ProcessEventJob $job): bool {
        expect(EventTruncator::size($job->rawEvent))->toBeLessThanOrEqual(200_000)
            ->and(strlen(serialize($job)))->toBeLessThan(250_000)
            ->and($job->rawEvent['request']['headers']['cookie'])->toBe('[Filtered]')
            ->and($job->rawEvent)->not->toHaveKey('modules');

        return true;
    });
});
