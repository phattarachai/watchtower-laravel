<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

use Phattarachai\WatchtowerCore\Sentry\EnvelopeItem;
use Phattarachai\WatchtowerCore\Sentry\EnvelopeParser;
use Phattarachai\WatchtowerCore\Support\Gzip;

final class ReplayEnvelope
{
    /** @var list<string> */
    public const array ITEM_TYPES = ['replay_event', 'replay_recording'];

    private const int MAX_INFLATED_BYTES = 20_971_520;

    public static function isReplay(string $body, ?string $contentEncoding = null): bool
    {
        $envelope = app(EnvelopeParser::class)->parse(Gzip::decodeBody($body, $contentEncoding, self::MAX_INFLATED_BYTES));

        return self::containsReplay($envelope['items']);
    }

    /**
     * @param  array<int, EnvelopeItem>  $items
     */
    public static function containsReplay(array $items): bool
    {
        foreach ($items as $item) {
            if (in_array($item->type(), self::ITEM_TYPES, true)) {
                return true;
            }
        }

        return false;
    }
}
