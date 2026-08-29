<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Phattarachai\WatchtowerLaravel\Server\Mcp\McpRegistrar;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;

/**
 * @param  array<string, mixed>  $params
 */
function callMcp(string $key, string $method, array $params = [], int $id = 1): TestResponse
{
    return test()->postJson('/watchtower/mcp', array_filter([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params === [] ? null : $params,
    ]), [
        'Authorization' => 'Bearer '.$key,
        'Accept' => 'application/json, text/event-stream',
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function callMcpTool(string $key, string $name, array $arguments = []): TestResponse
{
    return callMcp($key, 'tools/call', [
        'name' => $name,
        'arguments' => $arguments === [] ? new stdClass : $arguments,
    ], id: 3);
}

beforeEach(function (): void {
    $this->project = makeWatchtowerProject(['name' => 'Primary']);
    $this->key = $this->project->public_key;
});

it('lists every Watchtower tool', function (): void {
    $response = callMcp($this->key, 'tools/list');

    $response->assertOk();

    $tools = collect(data_get($response->json(), 'result.tools', []))->pluck('name')->all();

    expect($tools)->toContain(
        'list_issues',
        'get_issue',
        'list_events',
        'get_event',
        'get_stats',
        'resolve_issue',
        'ignore_issue',
        'unresolve_issue',
        'snooze_issue',
    );
});

it('registers the MCP route under the configured path prefix', function (): void {
    expect(Route::has('watchtower.server.mcp'))->toBeTrue()
        ->and(McpRegistrar::route())->toBe('/watchtower/mcp');
});

it('accepts the key as an api_key query parameter', function (): void {
    $response = test()->postJson('/watchtower/mcp?api_key='.$this->key, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertOk();
});

it('rejects a request without a bearer token', function (): void {
    $this->postJson('/watchtower/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Accept' => 'application/json, text/event-stream'])->assertStatus(401);
});

it('rejects a wrong bearer token', function (): void {
    callMcp('not-a-real-key', 'tools/list')->assertStatus(401);
});

it('rejects the key of a deactivated project', function (): void {
    $this->project->update(['is_active' => false]);

    callMcp($this->key, 'tools/list')->assertStatus(401);
});

it('reads issues scoped to the authenticated project', function (): void {
    $mine = makeWatchtowerGroup($this->project, ['title' => 'Mine explodes']);
    makeWatchtowerGroup(makeWatchtowerProject(['name' => 'Other']), ['title' => 'Theirs explodes']);

    $response = callMcpTool($this->key, 'list_issues');

    $response->assertOk();

    $issues = data_get($response->json(), 'result.structuredContent.issues');

    expect(data_get($response->json(), 'result.structuredContent.meta.total'))->toBe(1)
        ->and($issues)->toHaveCount(1)
        ->and($issues[0]['id'])->toBe((int) $mine->getKey())
        ->and($issues[0]['title'])->toBe('Mine explodes');
});

it('returns the latest event id from get_issue', function (): void {
    $group = makeWatchtowerGroup($this->project);
    makeWatchtowerEvent($group, ['received_at' => now()->subHour()]);
    $latest = makeWatchtowerEvent($group, ['received_at' => now()]);

    $response = callMcpTool($this->key, 'get_issue', ['issue_id' => (int) $group->getKey()]);

    $response->assertOk();

    expect(data_get($response->json(), 'result.structuredContent.issue.latest_event_id'))
        ->toBe((string) $latest->getKey());
});

it('resolves an issue through the mutate tool', function (): void {
    $group = makeWatchtowerGroup($this->project);

    $response = callMcpTool($this->key, 'resolve_issue', ['issue_id' => (int) $group->getKey()]);

    $response->assertOk();

    expect(data_get($response->json(), 'result.structuredContent.issue.status'))
        ->toBe(IssueGroup::STATUS_RESOLVED)
        ->and($group->refresh()->status)->toBe(IssueGroup::STATUS_RESOLVED)
        ->and($group->last_status_change_at)->not->toBeNull();
});

it('snoozes an issue for a bounded window', function (): void {
    $group = makeWatchtowerGroup($this->project);

    callMcpTool($this->key, 'snooze_issue', ['issue_id' => (int) $group->getKey(), 'duration' => '1h'])
        ->assertOk();

    expect($group->refresh()->status)->toBe(IssueGroup::STATUS_SNOOZED)
        ->and($group->snoozed_until)->not->toBeNull();

    callMcpTool($this->key, 'snooze_issue', ['issue_id' => (int) $group->getKey(), 'duration' => 'until_event'])
        ->assertOk();

    expect($group->refresh()->snoozed_until)->toBeNull();
});

it('refuses to mutate an issue that belongs to another project', function (): void {
    $foreign = makeWatchtowerGroup(makeWatchtowerProject(['name' => 'Other']));

    $response = callMcpTool($this->key, 'resolve_issue', ['issue_id' => (int) $foreign->getKey()]);

    $response->assertOk();

    expect(data_get($response->json(), 'result.isError'))->toBeTrue()
        ->and($foreign->refresh()->status)->toBe(IssueGroup::STATUS_UNRESOLVED);
});

it('summarises project health with get_stats', function (): void {
    $group = makeWatchtowerGroup($this->project);
    makeWatchtowerEvent($group);
    makeWatchtowerEvent($group);

    $response = callMcpTool($this->key, 'get_stats', ['window' => '7d']);

    $response->assertOk();

    $stats = data_get($response->json(), 'result.structuredContent');

    expect($stats['totals']['events'])->toBe(2)
        ->and($stats['totals']['events_by_level'])->toBe(['error' => 2])
        ->and($stats['top_issues'][0]['count_in_window'])->toBe(2)
        ->and($stats['status_mix']['unresolved'])->toBe(1);
});
