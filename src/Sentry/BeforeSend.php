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

    /**
     * Scrubbed whatever `scrub_keys` says, since a host's published config
     * predates new defaults. The SDK filters only Authorization and Cookie, so
     * without these the CSRF token Inertia and axios send on every request
     * (`X-XSRF-TOKEN`) and a form's `_token` reach the event as-is.
     *
     * @var list<string>
     */
    public const array ALWAYS_SCRUBBED_KEYS = ['x-csrf-token', 'x-xsrf-token', '_token'];

    /**
     * Query keys scrubbed from `request.url` and `request.query_string` on top
     * of `scrub_keys`: a Laravel signed URL is usable by whoever holds it.
     *
     * @var list<string>
     */
    public const array ALWAYS_SCRUBBED_QUERY_KEYS = ['signature'];

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

    /**
     * The `before_send_transaction` hook. A sampled transaction carries the
     * same request as an error event, so it gets the same scrub, but it has no
     * exception to drop it by.
     */
    public function transaction(Event $transaction, ?EventHint $hint = null): Event
    {
        if (config('watchtower.before_send.enabled') !== false) {
            $this->scrubEvent($transaction);
        }

        return $transaction;
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
            if (isset($request['url']) && is_string($request['url'])) {
                $request['url'] = $this->scrubUrl($request['url']);
            }

            if (isset($request['query_string']) && is_string($request['query_string'])) {
                $request['query_string'] = $this->scrubUrlPatterns($this->scrubQueryString($request['query_string']));
            }

            if (isset($request['data']) && is_array($request['data'])) {
                $request['data'] = $this->scrubArray($request['data']);
            }

            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = $this->scrubArray($request['headers']);
            }

            // With send_default_pii the SDK attaches every cookie, the session and
            // remember-me cookies included. Keep the names, never the values.
            if (isset($request['cookies']) && is_array($request['cookies'])) {
                $request['cookies'] = array_map(fn (): string => self::REDACTED, $request['cookies']);
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

    private function scrubUrl(string $url): string
    {
        [$withoutFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $withoutFragment, 2), 2, null);

        $scrubbed = $query === null ? $base : $base.'?'.$this->scrubQueryString($query);

        return $this->scrubUrlPatterns($fragment === null ? $scrubbed : $scrubbed.'#'.$fragment);
    }

    /**
     * Rewrites only the values whose key, or any bracketed segment of it
     * (`user[token]`), is a scrub key; every other pair keeps its encoding.
     */
    private function scrubQueryString(string $query): string
    {
        $keys = [...$this->normalizedScrubKeys(), ...self::ALWAYS_SCRUBBED_QUERY_KEYS];
        $pairs = explode('&', $query);

        foreach ($pairs as $index => $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            $name = strstr($pair, '=', true);
            $segments = preg_split('/[\[\]]+/', strtolower(urldecode($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (array_intersect($segments, $keys) !== []) {
                $pairs[$index] = $name.'='.self::REDACTED;
            }
        }

        return implode('&', $pairs);
    }

    /**
     * A pattern that does not compile filters the whole value rather than let
     * the token it was written for through.
     */
    private function scrubUrlPatterns(string $value): string
    {
        foreach ((array) config('watchtower.before_send.scrub_url_patterns', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $scrubbed = @preg_replace($pattern, self::REDACTED, $value);

            if ($scrubbed === null) {
                return self::REDACTED;
            }

            $value = $scrubbed;
        }

        return $value;
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
        $keys = [...(array) config('watchtower.before_send.scrub_keys', []), ...self::ALWAYS_SCRUBBED_KEYS];

        return array_values(array_unique(
            array_map(fn ($k): string => strtolower((string) $k), $keys)
        ));
    }
}
