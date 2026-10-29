<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Ingest\EventPipeline;
use Phattarachai\WatchtowerLaravel\Sentry\BeforeSend;
use Phattarachai\WatchtowerLaravel\Server\Ui\UiPage;
use Sentry\Event;
use Sentry\EventHint;

it('reads WATCHTOWER_UI_THEME from the env although the published 1.4 config has no ui.theme key', function (): void {
    expect(config('watchtower.server.ui.theme'))->toBe('light')
        ->and(UiPage::theme())->toBe('light');
});

it('keeps the lists the host published instead of merging the new defaults into them', function (): void {
    $legacy = require __DIR__.'/../Fixtures/config/watchtower-1.4.php';

    expect(config('watchtower.server.ingest.scrub.header_keys'))->toBe($legacy['server']['ingest']['scrub']['header_keys'])
        ->and(config('watchtower.before_send.scrub_keys'))->toBe($legacy['before_send']['scrub_keys'])
        ->and(config('watchtower.before_send.ignored_exceptions'))->toBe($legacy['before_send']['ignored_exceptions']);
});

it('still scrubs the CSRF headers and form token at the SDK under the 1.4 config', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'headers' => ['x-xsrf-token' => ['eyJpdiI6IkxzRmZ'], 'accept' => ['application/json']],
        'data' => ['_token' => 'Gk3uX9fHq'],
    ]);
    $hint = new EventHint;
    $hint->exception = new RuntimeException;

    $request = (new BeforeSend)($event, $hint)->getRequest();

    expect($request['headers']['x-xsrf-token'])->toBe('[Filtered]')
        ->and($request['headers']['accept'])->toBe(['application/json'])
        ->and($request['data']['_token'])->toBe('[Filtered]');
});

it('fills an empty scrub_url_patterns and still scrubs URL query tokens under the 1.4 config', function (): void {
    $event = Event::createEvent();
    $event->setRequest(['url' => 'https://shop.example.com/orders?token=abc&signature=4f9e2c&page=2']);
    $hint = new EventHint;
    $hint->exception = new RuntimeException;

    $request = (new BeforeSend)($event, $hint)->getRequest();

    expect(config('watchtower.before_send.scrub_url_patterns'))->toBe([])
        ->and($request['url'])->toBe('https://shop.example.com/orders?token=[Filtered]&signature=[Filtered]&page=2');
});

it('still scrubs the CSRF headers and form token at ingest under the 1.4 config', function (): void {
    $prepared = app(EventPipeline::class)->prepare([
        'event_id' => str_repeat('a', 32),
        'request' => [
            'headers' => ['X-XSRF-TOKEN' => 'eyJpdiI6IkxzRmZ', 'Accept' => 'text/html'],
            'data' => ['_token' => 'Gk3uX9fHq'],
        ],
    ]);

    expect($prepared['request']['headers']['X-XSRF-TOKEN'])->toBe('[Filtered]')
        ->and($prepared['request']['headers']['Accept'])->toBe('text/html')
        ->and($prepared['request']['data']['_token'])->toBe('[Filtered]');
});
