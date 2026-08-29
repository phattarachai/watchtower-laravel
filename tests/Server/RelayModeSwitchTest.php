<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function bindGuzzleHistory(array $responses, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    app()->instance(Client::class, new Client(['handler' => $stack]));
}

function tunnelEnvelope(Project $project): string
{
    return SentryEnvelope::build([], ['dsn' => $project->buildDsn('https://app.test')]);
}

it('ingests locally and never calls upstream in standalone mode', function (): void {
    $project = makeWatchtowerProject();
    $history = [];
    bindGuzzleHistory([new GuzzleResponse(200)], $history);

    $response = $this->call('POST', '/api/watchtower-relay', content: tunnelEnvelope($project));

    $response->assertSuccessful();
    expect($history)->toHaveCount(0)
        ->and(IssueGroup::count())->toBe(1)
        ->and(Event::sole()->project_id)->toBe($project->id);
});

it('rejects an envelope whose DSN key does not match', function (): void {
    $project = makeWatchtowerProject();
    $foreign = str_replace($project->public_key, str_repeat('b', 32), $project->buildDsn('https://app.test'));

    $response = $this->call('POST', '/api/watchtower-relay', content: SentryEnvelope::build([], ['dsn' => $foreign]));

    $response->assertNotFound()->assertJsonFragment(['error' => 'project_not_found']);
    expect(Event::count())->toBe(0);
});

it('stores locally and forwards upstream in dual mode', function (): void {
    config()->set('watchtower.mode', 'dual');
    $project = makeWatchtowerProject();

    $history = [];
    bindGuzzleHistory([new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"id":"evt_upstream"}')], $history);

    $response = $this->call('POST', '/api/watchtower-relay', content: tunnelEnvelope($project));

    $response->assertSuccessful();
    expect($response->getContent())->toBe('{"id":"evt_upstream"}')
        ->and($history)->toHaveCount(1)
        ->and(Event::count())->toBe(1);
});

it('forwards without touching local storage in relay mode', function (): void {
    config()->set('watchtower.mode', 'relay');
    $project = makeWatchtowerProject();

    $history = [];
    bindGuzzleHistory([new GuzzleResponse(200, [], '{"id":"evt_relay"}')], $history);

    $response = $this->call('POST', '/api/watchtower-relay', content: tunnelEnvelope($project));

    $response->assertSuccessful();
    expect($history)->toHaveCount(1)
        ->and(Event::count())->toBe(0);
});
