<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ingest;

/**
 * Bounds an event before it is queued, so one job payload can never grow past
 * `watchtower.server.max_event_bytes` in Redis — the self-capture incident grew
 * them from 120 KB to 2.9 MB because every SQL error quoted the job before it.
 *
 * Same spirit as Sentry's own event trimming: long strings and the breadcrumb
 * trail are always capped, then progressively heavier cuts run only until the
 * JSON fits — oldest breadcrumbs, `extra` and the request body, frame locals and
 * the middle of very deep stacks, and finally everything but the exception
 * itself. A trimmed event carries the `watchtower.truncated` tag.
 */
final class EventTruncator
{
    public const string TAG = 'watchtower.truncated';

    private const int MAX_BREADCRUMBS = 100;

    private const int MAX_DEPTH = 16;

    private const int FRAMES_KEPT_OUTER = 10;

    private const int FRAMES_KEPT_INNER = 40;

    private const int TIGHT_STRING_BYTES = 1024;

    private const int CUT_MARKER_BYTES = 40;

    private bool $trimmed = false;

    public function __construct(
        private readonly int $maxEventBytes,
        private readonly int $maxStringBytes,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxEventBytes: (int) config('watchtower.server.max_event_bytes', 200_000),
            maxStringBytes: (int) config('watchtower.server.max_string_bytes', 8_192),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function truncate(array $event): array
    {
        $this->trimmed = false;

        if ($this->maxEventBytes <= 0) {
            return $event;
        }

        $event = $this->keepLastBreadcrumbs($event, self::MAX_BREADCRUMBS);

        if ($this->maxStringBytes > 0) {
            $event = $this->capStrings($event, $this->maxStringBytes);
        }

        $stages = [
            fn (array $e): array => $this->stripBreadcrumbData($this->keepLastBreadcrumbs($e, 20)),
            fn (array $e): array => $this->dropBulkyContext($e),
            fn (array $e): array => $this->slimStacktraces($e),
            fn (array $e): array => $this->capStrings($this->keepLastBreadcrumbs($e, 0), self::TIGHT_STRING_BYTES),
            fn (array $e): array => $this->skeleton($e, withFrames: true),
            fn (array $e): array => $this->skeleton($e, withFrames: false),
        ];

        foreach ($stages as $stage) {
            if ($this->fits($event)) {
                break;
            }

            $event = $stage($event);
            $this->trimmed = true;
        }

        return $this->trimmed ? $this->mark($event) : $event;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function fits(array $event): bool
    {
        return self::size($event) <= $this->maxEventBytes;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function size(array $event): int
    {
        return strlen((string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Breadcrumbs arrive either as `{values: [...]}` or as a bare list; both
     * keep their most recent entries, which are the ones that explain the crash.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function keepLastBreadcrumbs(array $event, int $keep): array
    {
        $crumbs = $event['breadcrumbs'] ?? null;

        if (! is_array($crumbs)) {
            return $event;
        }

        $wrapped = isset($crumbs['values']) && is_array($crumbs['values']);
        $values = $wrapped ? $crumbs['values'] : $crumbs;

        if (! array_is_list($values) || count($values) <= $keep) {
            return $event;
        }

        $this->trimmed = true;
        $values = $keep === 0 ? [] : array_slice($values, -$keep);
        $event['breadcrumbs'] = $wrapped ? ['values' => $values] : $values;

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function stripBreadcrumbData(array $event): array
    {
        $values = $event['breadcrumbs']['values'] ?? null;

        if (! is_array($values)) {
            return $event;
        }

        $event['breadcrumbs']['values'] = array_map(
            fn (mixed $crumb): mixed => is_array($crumb) ? array_diff_key($crumb, ['data' => true]) : $crumb,
            $values,
        );

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function dropBulkyContext(array $event): array
    {
        unset($event['extra'], $event['modules'], $event['debug_meta'], $event['threads']);

        if (isset($event['request']) && is_array($event['request'])) {
            unset($event['request']['data'], $event['request']['cookies'], $event['request']['env']);
        }

        return $event;
    }

    /**
     * Drop frame locals and source context, and cut the middle out of very deep
     * stacks — the outermost frames say how the request started, the innermost
     * say what broke.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function slimStacktraces(array $event): array
    {
        $values = $event['exception']['values'] ?? null;

        if (is_array($values)) {
            foreach ($values as $i => $exception) {
                $frames = is_array($exception) ? ($exception['stacktrace']['frames'] ?? null) : null;

                if (is_array($frames)) {
                    $event['exception']['values'][$i]['stacktrace']['frames'] = $this->slimFrames($frames);
                }
            }
        }

        if (isset($event['stacktrace']['frames']) && is_array($event['stacktrace']['frames'])) {
            $event['stacktrace']['frames'] = $this->slimFrames($event['stacktrace']['frames']);
        }

        return $event;
    }

    /**
     * @param  array<mixed>  $frames
     * @return list<mixed>
     */
    private function slimFrames(array $frames): array
    {
        $frames = array_values($frames);

        if (count($frames) > self::FRAMES_KEPT_OUTER + self::FRAMES_KEPT_INNER) {
            $frames = [
                ...array_slice($frames, 0, self::FRAMES_KEPT_OUTER),
                ...array_slice($frames, -self::FRAMES_KEPT_INNER),
            ];
        }

        return array_map(function (mixed $frame): mixed {
            if (! is_array($frame)) {
                return $frame;
            }

            unset($frame['vars']);

            if (($frame['in_app'] ?? false) !== true) {
                unset($frame['pre_context'], $frame['post_context']);
            }

            return $frame;
        }, $frames);
    }

    /**
     * Last resort: what the issue page and the fingerprinter need, nothing else.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function skeleton(array $event, bool $withFrames): array
    {
        $kept = array_intersect_key($event, array_flip([
            'event_id', 'timestamp', 'platform', 'level', 'logger', 'transaction',
            'server_name', 'release', 'environment', 'sdk', 'fingerprint', 'message',
        ]));

        $values = $event['exception']['values'] ?? null;

        if (is_array($values)) {
            $kept['exception']['values'] = array_values(array_map(
                fn (mixed $exception): array => $this->skeletonException(is_array($exception) ? $exception : [], $withFrames),
                $values,
            ));
        }

        return $this->capStrings($kept, self::TIGHT_STRING_BYTES);
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    private function skeletonException(array $exception, bool $withFrames): array
    {
        $kept = array_intersect_key($exception, array_flip(['type', 'value', 'module']));
        $frames = $exception['stacktrace']['frames'] ?? null;

        if ($withFrames && is_array($frames)) {
            $kept['stacktrace']['frames'] = array_map(
                fn (mixed $frame): array => is_array($frame)
                    ? array_intersect_key($frame, array_flip(['filename', 'abs_path', 'function', 'module', 'lineno', 'in_app']))
                    : [],
                array_slice(array_values($frames), -self::FRAMES_KEPT_OUTER),
            );
        }

        return $kept;
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T|string
     */
    private function capStrings(mixed $value, int $limit, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return strlen($value) > $limit ? $this->cut($value, $limit) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            $this->trimmed = true;

            return '[depth limit]';
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->capStrings($item, $limit, $depth + 1);
        }

        return $value;
    }

    /**
     * The marker is budgeted inside the limit, so a cut string is never cut
     * again when the job re-runs the pipeline.
     */
    private function cut(string $value, int $limit): string
    {
        $this->trimmed = true;
        $kept = mb_strcut($value, 0, max(0, $limit - self::CUT_MARKER_BYTES), 'UTF-8');

        return $kept.'…[truncated '.(strlen($value) - strlen($kept)).' bytes]';
    }

    /**
     * Tags come as a map or as a list of `[key, value]` pairs.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function mark(array $event): array
    {
        $tags = $event['tags'] ?? [];

        if (! is_array($tags)) {
            $tags = [];
        }

        if ($tags !== [] && array_is_list($tags)) {
            $tags[] = [self::TAG, 'true'];
        } else {
            $tags[self::TAG] = 'true';
        }

        $event['tags'] = $tags;

        return $event;
    }
}
