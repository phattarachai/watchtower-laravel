<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Sentry;

use Sentry\Event;
use Sentry\EventHint;

class BeforeSend
{
    private const string REDACTED = '[Filtered]';

    /**
     * Matches Visa/MC/AmEx/Discover-shaped numbers (13–19 digits, optional
     * spaces or hyphens between digits — never trailing). Intentionally
     * permissive: false positives here just redact a number that looked
     * like a card; the cost of a false negative (real card leaking) is
     * much higher.
     */
    private const string CARD_PATTERN = '/\b\d(?:[ -]?\d){12,18}\b/';

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        if (config('watchtower.before_send.enabled') === false) {
            return $event;
        }

        if ($this->shouldDrop($hint)) {
            return null;
        }

        $this->scrubEvent($event);

        return $event;
    }

    private function shouldDrop(?EventHint $hint): bool
    {
        $exception = $hint?->exception;

        if ($exception === null) {
            return false;
        }

        $ignored = (array) config('watchtower.before_send.ignored_exceptions', []);

        foreach ($ignored as $class) {
            if (! is_string($class)) {
                continue;
            }

            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function scrubEvent(Event $event): void
    {
        $request = $event->getRequest();

        if ($request !== []) {
            if (isset($request['data']) && is_array($request['data'])) {
                $request['data'] = $this->scrubArray($request['data']);
            }

            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = $this->scrubArray($request['headers']);
            }

            if (isset($request['cookies']) && is_array($request['cookies'])) {
                $request['cookies'] = $this->scrubArray($request['cookies']);
            }

            $event->setRequest($request);
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra($this->scrubArray($extra));
        }
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function scrubArray(array $values): array
    {
        $keys = $this->normalizedScrubKeys();

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->scrubArray($value);

                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->scrubCardShapes($value);
            }
        }

        return $values;
    }

    private function scrubCardShapes(string $value): string
    {
        return (string) preg_replace(self::CARD_PATTERN, self::REDACTED, $value);
    }

    /**
     * @return array<int, string>
     */
    private function normalizedScrubKeys(): array
    {
        $keys = (array) config('watchtower.before_send.scrub_keys', []);

        return array_values(array_unique(
            array_map(fn ($k): string => strtolower((string) $k), $keys)
        ));
    }
}
