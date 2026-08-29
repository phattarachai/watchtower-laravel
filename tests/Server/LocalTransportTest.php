<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Sentry\LocalTransport;
use Sentry\ClientBuilder;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\Hub;

function hubForProject(Project $project): Hub
{
    $builder = ClientBuilder::create([
        'dsn' => $project->buildDsn('https://host.test'),
        'default_integrations' => false,
        'environment' => 'testing',
        'release' => '9.9.9',
    ]);

    $builder->setTransport(new LocalTransport(
        new PayloadSerializer($builder->getOptions()),
        app(EnvelopeAccepter::class),
    ));

    return new Hub($builder->getClient());
}

it('writes a group and an event row for a captured exception', function (): void {
    $project = makeWatchtowerProject();
    $hub = hubForProject($project);

    $hub->captureException(new RuntimeException('the boiler exploded'));

    $group = IssueGroup::query()->firstOrFail();
    $event = Event::query()->firstOrFail();

    expect(IssueGroup::query()->count())->toBe(1)
        ->and($group->project_id)->toBe((int) $project->getKey())
        ->and($group->title)->toContain('RuntimeException')
        ->and($group->title)->toContain('the boiler exploded')
        ->and($event->project_id)->toBe((int) $project->getKey())
        ->and($event->group_id)->toBe((int) $group->getKey())
        ->and($event->environment)->toBe('testing')
        ->and($event->release)->toBe('9.9.9')
        ->and(data_get($event->payload, 'exception.values.0.type'))->toBe('RuntimeException');
});

it('groups repeat captures of the same exception', function (): void {
    $hub = hubForProject(makeWatchtowerProject());
    $throw = fn (): RuntimeException => new RuntimeException('repeat');

    $hub->captureException($throw());
    $hub->captureException($throw());

    expect(IssueGroup::query()->count())->toBe(1)
        ->and(Event::query()->count())->toBe(2)
        ->and(IssueGroup::query()->firstOrFail()->event_count)->toBe(2);
});

it('drops an event whose DSN does not resolve to a live project', function (): void {
    $project = makeWatchtowerProject();
    $hub = hubForProject($project);

    $project->update(['is_active' => false]);

    $hub->captureException(new RuntimeException('nowhere to go'));

    expect(Event::query()->count())->toBe(0);
});

it('still honours a BeforeSend drop', function (): void {
    $project = makeWatchtowerProject();

    $builder = ClientBuilder::create([
        'dsn' => $project->buildDsn('https://host.test'),
        'default_integrations' => false,
        'before_send' => fn ($event, $hint) => app(BeforeSend::class)($event, $hint),
    ]);

    $builder->setTransport(new LocalTransport(
        new PayloadSerializer($builder->getOptions()),
        app(EnvelopeAccepter::class),
    ));

    new Hub($builder->getClient())->captureException(ValidationException::withMessages(['x' => 'y']));

    expect(Event::query()->count())->toBe(0);
});

it('swaps the transport sentry-laravel would have built', function (): void {
    config()->set('sentry.dsn', makeWatchtowerProject()->buildDsn('https://host.test'));
    $this->app->register(SentryServiceProvider::class);

    expect(app(ClientBuilder::class)->getTransport())->toBeInstanceOf(LocalTransport::class);
});
