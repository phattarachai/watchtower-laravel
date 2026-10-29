<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Tests\Fixtures\HostSentryCallbacks;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Options;
use Sentry\SentrySdk;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function sentryOptions(): Options
{
    return SentrySdk::getCurrentHub()->getClient()?->getOptions() ?? throw new RuntimeException('Sentry client is not booted');
}

function feedRequest(Event $event): Event
{
    $event->setRequest(['url' => 'https://shop.example.com/feeds/s3cr3t-feed-token/orders.csv?token=abc']);

    return $event;
}

it('scrubs an error event before the host before_send sees it', function (): void {
    $beforeSend = sentryOptions()->getBeforeSendCallback();

    $event = $beforeSend(feedRequest(Event::createEvent()), EventHint::fromArray(['exception' => new RuntimeException('boom')]));

    expect($event->getRequest()['url'])->toBe('https://shop.example.com/feeds/[Filtered]/orders.csv?token=[Filtered]')
        ->and($event->getTags())->toHaveKey('host.before_send')
        ->and(HostSentryCallbacks::$seenUrls)->toBe(['https://shop.example.com/feeds/[Filtered]/orders.csv?token=[Filtered]']);
});

it('scrubs a transaction before the host before_send_transaction sees it', function (): void {
    $beforeSendTransaction = sentryOptions()->getBeforeSendTransactionCallback();

    $transaction = $beforeSendTransaction(feedRequest(Event::createTransaction()), new EventHint);

    expect($transaction->getRequest()['url'])->toBe('https://shop.example.com/feeds/[Filtered]/orders.csv?token=[Filtered]')
        ->and($transaction->getTags())->toHaveKey('host.before_send_transaction')
        ->and(HostSentryCallbacks::$seenUrls)->toBe(['https://shop.example.com/feeds/[Filtered]/orders.csv?token=[Filtered]']);
});

it('keeps a transaction whose hint carries an ignored exception', function (): void {
    $beforeSendTransaction = sentryOptions()->getBeforeSendTransactionCallback();

    $transaction = $beforeSendTransaction(feedRequest(Event::createTransaction()), EventHint::fromArray(['exception' => new NotFoundHttpException]));

    expect($transaction)->not->toBeNull();
});
