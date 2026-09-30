<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Phattarachai\WatchtowerLaravel\Sentry\SelfCaptureGuard;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Jobs\ProcessEventJob;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;
use Sentry\ClientBuilder;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\Hub;

function selfCaptureHub(Project $project): Hub
{
    $builder = ClientBuilder::create([
        'dsn' => $project->buildDsn('https://host.test'),
        'default_integrations' => false,
    ]);

    $builder->setTransport(new LocalTransport(
        new PayloadSerializer($builder->getOptions()),
        app(EnvelopeAccepter::class),
        app(SelfCaptureGuard::class),
    ));

    return new Hub($builder->getClient());
}

function captureRepeatedly(Hub $hub, int $times, string $message = 'hot loop'): void
{
    foreach (range(1, $times) as $_) {
        $hub->captureException(new RuntimeException($message));
    }
}

it('applies the project budget to in-process self-capture', function (): void {
    config()->set('watchtower.server.rate_limit_per_min', 3);
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 0);
    $hub = selfCaptureHub(makeWatchtowerProject());

    foreach (range(1, 5) as $i) {
        $hub->captureException(new RuntimeException("distinct {$i}"));
    }

    expect(Event::query()->count())->toBe(3);
});

it('dampens one hot fingerprint but still counts every occurrence on its group', function (): void {
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 2);
    $hub = selfCaptureHub(makeWatchtowerProject());

    captureRepeatedly($hub, 10);

    $group = IssueGroup::query()->sole();

    expect(Event::query()->count())->toBe(2)
        ->and($group->event_count)->toBe(10);
});

it('leaves budget for other issues while one fingerprint is dampened', function (): void {
    config()->set('watchtower.server.rate_limit_per_min', 10);
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 2);
    $hub = selfCaptureHub(makeWatchtowerProject());

    captureRepeatedly($hub, 20);
    $hub->captureException(new LogicException('a different bug'));

    expect(IssueGroup::query()->count())->toBe(2)
        ->and(Event::query()->count())->toBe(3);
});

it('does not queue a job for a dampened event', function (): void {
    Queue::fake();
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 1);
    $hub = selfCaptureHub(makeWatchtowerProject());

    captureRepeatedly($hub, 5);

    Queue::assertPushed(ProcessEventJob::class, 1);
});

it('applies the budgets to the relay in standalone mode', function (): void {
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 1);
    $project = makeWatchtowerProject();
    $dsn = $project->buildDsn('https://host.test');

    foreach (range(1, 3) as $_) {
        $this->call('POST', '/api/watchtower-relay', [], [], [], ['CONTENT_TYPE' => 'application/x-sentry-envelope'], SentryEnvelope::build([], ['dsn' => $dsn]))
            ->assertSuccessful();
    }

    expect(Event::query()->count())->toBe(1)
        ->and(IssueGroup::query()->sole()->event_count)->toBe(3);
});

it('admits everything when both budgets are disabled', function (): void {
    config()->set('watchtower.server.rate_limit_per_min', 0);
    config()->set('watchtower.server.rate_limit_per_fingerprint_per_min', 0);
    $hub = selfCaptureHub(makeWatchtowerProject());

    captureRepeatedly($hub, 25);

    expect(Event::query()->count())->toBe(25);
});
