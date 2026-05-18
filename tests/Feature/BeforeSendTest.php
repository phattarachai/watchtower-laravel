<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function makeRequestEvent(array $request = [], array $extra = []): Event
{
    $event = Event::createEvent();
    $event->setRequest($request);
    $event->setExtra($extra);

    return $event;
}

function hintFor(?\Throwable $exception): EventHint
{
    $hint = new EventHint;
    $hint->exception = $exception;

    return $hint;
}

beforeEach(function (): void {
    // The test's defineEnvironment in TestCase doesn't seed before_send config —
    // mergeConfigFrom from the provider does. Re-assert defaults so tests are
    // deterministic regardless of config-cache state.
    config(['watchtower.before_send.enabled' => true]);
    config(['watchtower.before_send.ignored_exceptions' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        NotFoundHttpException::class,
    ]]);
    config(['watchtower.before_send.scrub_keys' => [
        'password', 'password_confirmation', 'authorization', 'cookie',
    ]]);
});

it('drops events whose exception is in the ignored list', function (): void {
    $event = makeRequestEvent();
    $hint  = hintFor(ValidationException::withMessages(['x' => 'y']));

    expect((new BeforeSend)($event, $hint))->toBeNull();
});

it('drops 404 exceptions', function (): void {
    $event = makeRequestEvent();
    $hint  = hintFor(new NotFoundHttpException);

    expect((new BeforeSend)($event, $hint))->toBeNull();
});

it('passes through exceptions not in the ignored list', function (): void {
    $event = makeRequestEvent(['data' => ['name' => 'alice']]);
    $hint  = hintFor(new RuntimeException('boom'));

    $result = (new BeforeSend)($event, $hint);

    expect($result)->not->toBeNull();
    expect($result->getRequest()['data']['name'])->toBe('alice');
});

it('redacts scrub_keys from request data (case-insensitive)', function (): void {
    $event = makeRequestEvent([
        'data' => [
            'name'                  => 'alice',
            'password'              => 'plaintext',
            'Password_Confirmation' => 'plaintext',
        ],
    ]);

    $result = (new BeforeSend)($event, hintFor(new RuntimeException));

    $data = $result->getRequest()['data'];
    expect($data['name'])->toBe('alice');
    expect($data['password'])->toBe('[Filtered]');
    expect($data['Password_Confirmation'])->toBe('[Filtered]');
});

it('redacts scrub_keys from request headers', function (): void {
    $event = makeRequestEvent([
        'headers' => [
            'User-Agent'    => 'Mozilla',
            'Authorization' => 'Bearer secret-token',
            'Cookie'        => 'session=abc',
        ],
    ]);

    $result = (new BeforeSend)($event, hintFor(new RuntimeException));

    $headers = $result->getRequest()['headers'];
    expect($headers['User-Agent'])->toBe('Mozilla');
    expect($headers['Authorization'])->toBe('[Filtered]');
    expect($headers['Cookie'])->toBe('[Filtered]');
});

it('redacts credit-card-shaped numbers in request data', function (): void {
    $event = makeRequestEvent([
        'data' => [
            'note' => 'paid with 4242 4242 4242 4242 today',
        ],
    ]);

    $result = (new BeforeSend)($event, hintFor(new RuntimeException));

    expect($result->getRequest()['data']['note'])
        ->toBe('paid with [Filtered] today');
});

it('walks nested arrays when scrubbing', function (): void {
    $event = makeRequestEvent([
        'data' => [
            'user' => [
                'email'    => 'a@b.com',
                'password' => 'plaintext',
            ],
        ],
    ]);

    $result = (new BeforeSend)($event, hintFor(new RuntimeException));

    $user = $result->getRequest()['data']['user'];
    expect($user['email'])->toBe('a@b.com');
    expect($user['password'])->toBe('[Filtered]');
});

it('scrubs extra data alongside the request', function (): void {
    $event = makeRequestEvent(extra: [
        'context' => 'job',
        'token'   => 'should-be-scrubbed',
    ]);
    config(['watchtower.before_send.scrub_keys' => ['token']]);

    $result = (new BeforeSend)($event, hintFor(new RuntimeException));

    $extra = $result->getExtra();
    expect($extra['context'])->toBe('job');
    expect($extra['token'])->toBe('[Filtered]');
});

it('is a no-op when disabled', function (): void {
    config(['watchtower.before_send.enabled' => false]);
    $event = makeRequestEvent(['data' => ['password' => 'plaintext']]);
    $hint  = hintFor(new ValidationException(validator(['x' => null], ['x' => 'required'])));

    $result = (new BeforeSend)($event, $hint);

    expect($result)->not->toBeNull();
    expect($result->getRequest()['data']['password'])->toBe('plaintext');
});

it('honors a custom scrub_keys config', function (): void {
    config(['watchtower.before_send.scrub_keys' => ['custom_secret']]);
    $event = makeRequestEvent([
        'data' => ['custom_secret' => 'shh', 'password' => 'leaked-on-purpose'],
    ]);

    $result = (new BeforeSend)($event, hintFor(new RuntimeException));

    $data = $result->getRequest()['data'];
    expect($data['custom_secret'])->toBe('[Filtered]');
    expect($data['password'])->toBe('leaked-on-purpose');
});
