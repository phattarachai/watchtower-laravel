<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Watchtower;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects a guest to the login screen and remembers where they were going', function (): void {
    get(route('watchtower.ui.issues'))->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('watchtower.ui.issues'));
});

it('forbids a guest instead when no login route is configured', function (): void {
    config()->set('watchtower.server.ui.redirect_guests_to');

    get(route('watchtower.ui.issues'))->assertForbidden();
});

it('forbids a guest XHR rather than redirecting it', function (): void {
    get(route('watchtower.ui.issues'), ['Accept' => 'application/json'])->assertForbidden();
});

it('forbids a signed-in user the gate rejects, without looping back to login', function (): void {
    Watchtower::auth(fn (): bool => false);

    actingAs(wtUser())->get(route('watchtower.ui.issues'))->assertForbidden();
});

it('keeps every write endpoint behind the gate', function (): void {
    Watchtower::auth(fn (): bool => false);

    $group = makeWatchtowerGroup(makeWatchtowerProject());

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.issues.status', ['group' => $group->getKey()]), ['status' => 'resolved'])
        ->assertForbidden();

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.projects.store'), ['name' => 'Nope', 'platform' => 'laravel'])
        ->assertForbidden();
});

it('falls back to the viewWatchtower gate when no closure is registered', function (): void {
    Watchtower::flushAuth();

    actingAs(wtUser())->get(route('watchtower.ui.issues'))->assertForbidden();

    Gate::define('viewWatchtower', fn (): bool => true);

    actingAs(wtUser())->get(route('watchtower.ui.issues'))->assertOk();
});

it('opens the console to everyone on a local machine', function (): void {
    Watchtower::auth(fn (): bool => false);
    app()['env'] = 'local';

    get(route('watchtower.ui.issues'))->assertOk();
});

it('registers every UI route under the configured path prefix', function (): void {
    expect(route('watchtower.ui.issues', absolute: false))->toBe('/watchtower')
        ->and(route('watchtower.ui.alerts', absolute: false))->toBe('/watchtower/alerts')
        ->and(route('watchtower.ui.settings', absolute: false))->toBe('/watchtower/settings')
        ->and(Route::has('watchtower.server.ingest.envelope'))->toBeTrue();
});

it('serves the issue URL the alert mail links to', function (): void {
    $group = makeWatchtowerGroup(makeWatchtowerProject());
    $prefix = trim((string) config('watchtower.server.path'), '/');

    expect(route('watchtower.ui.issue', ['group' => $group->getKey()]))
        ->toBe(url($prefix.'/issues/'.$group->getKey()));

    actingAs(wtUser())->get(url($prefix.'/issues/'.$group->getKey()))->assertOk();
});
