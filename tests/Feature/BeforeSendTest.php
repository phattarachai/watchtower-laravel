<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function makeRequestEvent(array $request = [], array $extra = []): Event
{
    $event = Event::createEvent();
    $event->setRequest($request);
    $event->setExtra($extra);

    return $event;
}

function hintFor(?Throwable $exception): EventHint
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
        ModelNotFoundException::class,
        TokenMismatchException::class,
        NotFoundHttpException::class,
        AccessDeniedHttpException::class,
        SuspiciousOperationException::class,
    ]]);
    config(['watchtower.before_send.scrub_keys' => [
        'password', 'password_confirmation', 'authorization', 'cookie',
    ]]);
    config(['watchtower.before_send.scrub_url_patterns' => []]);
});

it('drops events whose exception is in the ignored list', function (): void {
    $event = makeRequestEvent();
    $hint = hintFor(ValidationException::withMessages(['x' => 'y']));

    expect((new BeforeSend)($event, $hint))->toBeNull();
});

it('drops 404 exceptions', function (): void {
    $event = makeRequestEvent();
    $hint = hintFor(new NotFoundHttpException);

    expect((new BeforeSend)($event, $hint))->toBeNull();
});

it('drops ModelNotFoundException (unwrapped findOrFail from job/console)', function (): void {
    $exception = (new ModelNotFoundException)->setModel(Model::class, [123]);
    $event = makeRequestEvent();

    expect((new BeforeSend)($event, hintFor($exception)))->toBeNull();
});

it('drops TokenMismatchException (CSRF — almost always stale session noise)', function (): void {
    $event = makeRequestEvent();

    expect((new BeforeSend)($event, hintFor(new TokenMismatchException)))->toBeNull();
});

it('drops Symfony AccessDeniedHttpException (HTTP-level 403, twin of AuthorizationException)', function (): void {
    $event = makeRequestEvent();

    expect((new BeforeSend)($event, hintFor(new AccessDeniedHttpException)))->toBeNull();
});

it('drops SuspiciousOperationException (bot probing for malicious paths/headers)', function (): void {
    $event = makeRequestEvent();

    expect((new BeforeSend)($event, hintFor(new SuspiciousOperationException)))->toBeNull();
});

it('passes through exceptions not in the ignored list', function (): void {
    $event = makeRequestEvent(['data' => ['name' => 'alice']]);
    $hint = hintFor(new RuntimeException('boom'));

    $result = (new BeforeSend)($event, $hint);

    expect($result)->not->toBeNull();
    expect($result->getRequest()['data']['name'])->toBe('alice');
});

it('redacts scrub_keys from request data (case-insensitive)', function (): void {
    $event = makeRequestEvent([
        'data' => [
            'name' => 'alice',
            'password' => 'plaintext',
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
            'User-Agent' => 'Mozilla',
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'session=abc',
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
                'email' => 'a@b.com',
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
        'token' => 'should-be-scrubbed',
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
    $hint = hintFor(new ValidationException(validator(['x' => null], ['x' => 'required'])));

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

it('always redacts the CSRF headers and form token, whatever scrub_keys says', function (): void {
    // A host's published config predates these keys, so they can't rely on it.
    config(['watchtower.before_send.scrub_keys' => ['password']]);
    $event = makeRequestEvent([
        'headers' => [
            'x-xsrf-token' => ['eyJpdiI6IkxzRmZ'],
            'X-CSRF-TOKEN' => ['Gk3uX9fHq'],
            'accept' => ['application/json'],
        ],
        'data' => ['_token' => 'Gk3uX9fHq', 'quantity' => 2],
    ]);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['headers']['x-xsrf-token'])->toBe('[Filtered]')
        ->and($request['headers']['X-CSRF-TOKEN'])->toBe('[Filtered]')
        ->and($request['headers']['accept'])->toBe(['application/json'])
        ->and($request['data']['_token'])->toBe('[Filtered]')
        ->and($request['data']['quantity'])->toBe(2);
});

it('keeps cookie names but filters every cookie value', function (): void {
    $event = makeRequestEvent([
        'cookies' => ['laravel_session' => 'eyJpdiI6Ij', 'XSRF-TOKEN' => 'eyJpdiI6Ik', 'appearance' => 'dark'],
    ]);

    $cookies = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest()['cookies'];

    expect($cookies)->toBe(['laravel_session' => '[Filtered]', 'XSRF-TOKEN' => '[Filtered]', 'appearance' => '[Filtered]']);
});

it('redacts scrub_keys values in the query of request.url and request.query_string', function (): void {
    config(['watchtower.before_send.scrub_keys' => ['token', 'api_key']]);
    $event = makeRequestEvent([
        'url' => 'https://shop.example.com/orders?page=2&token=abc123&API_KEY=k-9',
        'query_string' => 'page=2&token=abc123&API_KEY=k-9',
    ]);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['url'])->toBe('https://shop.example.com/orders?page=2&token=[Filtered]&API_KEY=[Filtered]')
        ->and($request['query_string'])->toBe('page=2&token=[Filtered]&API_KEY=[Filtered]');
});

it('matches encoded and bracketed query keys and leaves a key without a value alone', function (): void {
    config(['watchtower.before_send.scrub_keys' => ['token']]);
    $event = makeRequestEvent([
        'query_string' => 'user%5Btoken%5D=abc&tokens[]=keep&token&q=a+b',
    ]);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['query_string'])->toBe('user%5Btoken%5D=[Filtered]&tokens[]=keep&token&q=a+b');
});

it('always redacts a signed URL signature, whatever scrub_keys says', function (): void {
    config(['watchtower.before_send.scrub_keys' => ['password']]);
    $event = makeRequestEvent([
        'url' => 'https://shop.example.com/unsubscribe/7?expires=1767225600&signature=4f9e2c',
    ]);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['url'])->toBe('https://shop.example.com/unsubscribe/7?expires=1767225600&signature=[Filtered]');
});

it('replaces scrub_url_patterns matches in the URL path', function (): void {
    config(['watchtower.before_send.scrub_url_patterns' => ['#/feeds/\K[^/?]+#']]);
    $event = makeRequestEvent([
        'url' => 'https://shop.example.com/feeds/s3cr3t-feed-token/orders.csv?page=1',
        'query_string' => 'page=1',
    ]);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['url'])->toBe('https://shop.example.com/feeds/[Filtered]/orders.csv?page=1')
        ->and($request['query_string'])->toBe('page=1');
});

it('leaves the URL path alone when no scrub_url_patterns are set', function (): void {
    config(['watchtower.before_send.scrub_url_patterns' => []]);
    $event = makeRequestEvent(['url' => 'https://shop.example.com/feeds/abc/orders.csv']);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['url'])->toBe('https://shop.example.com/feeds/abc/orders.csv');
});

it('filters the whole URL when a scrub_url_patterns entry does not compile', function (): void {
    config(['watchtower.before_send.scrub_url_patterns' => ['#/feeds/[^/?+']]);
    $event = makeRequestEvent([
        'url' => 'https://shop.example.com/feeds/s3cr3t-feed-token/orders.csv',
        'query_string' => 'page=1',
    ]);

    $request = (new BeforeSend)($event, hintFor(new RuntimeException))->getRequest();

    expect($request['url'])->toBe('[Filtered]')
        ->and($request['query_string'])->toBe('[Filtered]');
});

it('scrubs a transaction like an error event but never drops it', function (): void {
    config(['watchtower.before_send.scrub_keys' => ['token', 'authorization']]);
    config(['watchtower.before_send.scrub_url_patterns' => ['#/feeds/\K[^/?]+#']]);
    $transaction = Event::createTransaction();
    $transaction->setRequest([
        'url' => 'https://shop.example.com/feeds/s3cr3t-feed-token/orders.csv?token=abc',
        'headers' => ['Authorization' => 'Bearer secret-token'],
    ]);

    $result = (new BeforeSend)->transaction($transaction, hintFor(new NotFoundHttpException));

    expect($result)->toBe($transaction)
        ->and($result->getRequest()['url'])->toBe('https://shop.example.com/feeds/[Filtered]/orders.csv?token=[Filtered]')
        ->and($result->getRequest()['headers']['Authorization'])->toBe('[Filtered]');
});

it('leaves a transaction untouched when disabled', function (): void {
    config(['watchtower.before_send.enabled' => false]);
    $transaction = Event::createTransaction();
    $transaction->setRequest(['url' => 'https://shop.example.com/orders?token=abc']);

    $result = (new BeforeSend)->transaction($transaction);

    expect($result->getRequest()['url'])->toBe('https://shop.example.com/orders?token=abc');
});
