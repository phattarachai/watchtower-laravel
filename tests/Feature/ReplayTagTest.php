<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Phattarachai\WatchtowerLaravel\Http\Middleware\WatchtowerUserContext;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;

beforeEach(function (): void {
    SentrySdk::setCurrentHub(new Hub);
});

/**
 * @return array<string, string>
 */
function scopeTags(): array
{
    $tags = [];

    \Sentry\configureScope(function (Scope $scope) use (&$tags): void {
        $event = Event::createEvent();
        $scope->applyToEvent($event);
        $tags = $event->getTags();
    });

    return $tags;
}

function runUserContext(?string $replayHeader): void
{
    $request = Request::create('/orders');

    if ($replayHeader !== null) {
        $request->headers->set('X-Watchtower-Replay-Id', $replayHeader);
    }

    (new WatchtowerUserContext)->handle($request, fn () => response('ok'));
}

it('tags the PHP scope with the replay id the browser sent', function (): void {
    runUserContext(str_repeat('ab', 16));

    expect(scopeTags())->toMatchArray(['replayId' => str_repeat('ab', 16)]);
});

it('normalises the case of a replay id', function (): void {
    runUserContext(str_repeat('AB', 16));

    expect(scopeTags()['replayId'] ?? null)->toBe(str_repeat('ab', 16));
});

it('ignores a missing or malformed replay id', function (): void {
    $cases = [
        'absent' => null,
        'empty' => '',
        'too short' => str_repeat('a', 31),
        'dashed uuid' => '7252b797-bb20-40ec-b481-edff6926778a',
        'not hex' => str_repeat('z', 32),
        'injection' => str_repeat('a', 32)."\nX: y",
    ];

    foreach ($cases as $name => $header) {
        SentrySdk::setCurrentHub(new Hub);
        runUserContext($header);

        expect(scopeTags())->not->toHaveKey('replayId', $name);
    }
});

it('tags the replay even when user context is turned off', function (): void {
    config()->set('watchtower.user_context.enabled', false);

    runUserContext(str_repeat('c', 32));

    expect(scopeTags())->toMatchArray(['replayId' => str_repeat('c', 32)]);
});
