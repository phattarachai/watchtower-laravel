<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests\Fixtures;

use Sentry\Event;
use Sentry\EventHint;

/**
 * A host's own `before_send` / `before_send_transaction` in config/sentry.php,
 * as `[Class, 'method']` arrays so `php artisan optimize` can cache them.
 * Records the URL it was handed, to prove Watchtower scrubbed it first.
 */
class HostSentryCallbacks
{
    /** @var list<string|null> */
    public static array $seenUrls = [];

    public static function event(Event $event, ?EventHint $hint = null): Event
    {
        self::$seenUrls[] = $event->getRequest()['url'] ?? null;
        $event->setTag('host.before_send', 'ran');

        return $event;
    }

    public static function transaction(Event $transaction, ?EventHint $hint = null): Event
    {
        self::$seenUrls[] = $transaction->getRequest()['url'] ?? null;
        $transaction->setTag('host.before_send_transaction', 'ran');

        return $transaction;
    }
}
