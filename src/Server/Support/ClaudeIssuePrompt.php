<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Support;

use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

/**
 * Builds the self-contained Markdown prompt copied by the "Copy Markdown" button
 * on the issue detail page — the embedded mirror of the central server's
 * `App\Support\ClaudeIssuePrompt`.
 */
class ClaudeIssuePrompt
{
    public function __construct(private readonly EventOrigin $origin) {}

    public function build(Project $project, IssueGroup $group, ?Event $event, string $permalink): string
    {
        if (! $event instanceof Event) {
            return $this->mcpFallback($project, $group, $permalink);
        }

        $payload = (array) ($event->payload ?? []);
        $frames = $this->arrayFrames(data_get($payload, 'exception.values.0.stacktrace.frames'));

        return implode("\n\n", array_filter([
            $this->instructions($project, $group),
            $this->heading($payload),
            $this->meta($group, $event, $permalink),
            $this->stackTrace($frames),
            $this->culprit($frames),
        ]));
    }

    private function instructions(Project $project, IssueGroup $group): string
    {
        $id = $group->getKey();
        $slug = $project->slug;

        return "Fix this issue from Watchtower — project \"{$slug}\", issue #{$id}.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function heading(array $payload): string
    {
        $type = (string) data_get($payload, 'exception.values.0.type', 'Error');
        $message = trim((string) data_get($payload, 'exception.values.0.value', ''));

        return $message === '' ? "# {$type}" : "# {$type}\n\n{$message}";
    }

    private function meta(IssueGroup $group, Event $event, string $permalink): string
    {
        $origin = $this->origin->resolve((array) ($event->payload ?? []));
        $environment = ($event->environment ?? '—').($event->release ? " · release {$event->release}" : '');

        return implode("\n", array_filter([
            "- Environment: {$environment}",
            "- Level: {$group->level}",
            "- Occurrences: {$group->event_count} events · {$group->user_count} users",
            $origin ? "- Where: {$origin['primary']}" : null,
            "- Permalink: {$permalink}",
        ]));
    }

    /**
     * App frames only, throw site first — mirrors the page's "Most Relevant" view.
     * Vendor frames are collapsed to a count so a 100+-frame trace stays cheap to
     * paste; the culprit block below pinpoints the exact line.
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function stackTrace(array $frames): ?string
    {
        if ($frames === []) {
            return null;
        }

        // Sentry stores frames innermost-last; reverse so the throw site is first.
        $ordered = array_reverse($frames);
        $appFrames = array_filter($ordered, fn ($frame) => ($frame['in_app'] ?? false) === true);

        // When no frame is flagged in_app, fall back to the whole trace so it isn't empty.
        $shown = $appFrames !== [] ? $appFrames : $ordered;
        $hidden = count($ordered) - count($shown);

        $lines = [];
        foreach (array_values($shown) as $i => $frame) {
            $file = $frame['filename'] ?? $frame['abs_path'] ?? '(unknown)';
            $line = $frame['lineno'] ?? 0;
            $lines[] = ($i + 1).". {$file}:{$line}";
        }

        if ($hidden > 0) {
            $lines[] = '… +'.$hidden.' vendor '.str('frame')->plural($hidden);
        }

        return "## Stack Trace\n\n".implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function culprit(array $frames): ?string
    {
        $frame = $this->topAppFrame($frames);
        if ($frame === null) {
            return null;
        }

        $file = $frame['filename'] ?? $frame['abs_path'] ?? '(unknown)';
        $line = (int) ($frame['lineno'] ?? 0);
        $header = "## Culprit — {$file}:{$line}";
        $context = $this->codeContext($frame);

        return $context === null ? $header : "{$header}\n\n{$context}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<string, mixed>|null
     */
    private function topAppFrame(array $frames): ?array
    {
        // Sentry orders frames innermost-last; the culprit is the last in_app frame.
        $appFrames = array_values(array_filter(
            $frames,
            fn ($frame) => ($frame['in_app'] ?? false) === true,
        ));

        $frame = $appFrames !== [] ? end($appFrames) : end($frames);

        return is_array($frame) ? $frame : null;
    }

    /**
     * Keep only the well-formed frame arrays — a malformed payload can carry
     * scalars in the frame list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function arrayFrames(mixed $frames): array
    {
        return is_array($frames)
            ? array_values(array_filter($frames, is_array(...)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function codeContext(array $frame): ?string
    {
        $contextLine = $frame['context_line'] ?? null;
        if ($contextLine === null) {
            return null;
        }

        $pre = $frame['pre_context'] ?? [];
        $post = $frame['post_context'] ?? [];
        $lineno = (int) ($frame['lineno'] ?? 0);
        $start = $lineno - count($pre);

        $rows = [];
        foreach ($pre as $offset => $code) {
            $rows[] = $this->codeRow($start + $offset, $code, culprit: false);
        }
        $rows[] = $this->codeRow($lineno, $contextLine, culprit: true);
        foreach ($post as $offset => $code) {
            $rows[] = $this->codeRow($lineno + $offset + 1, $code, culprit: false);
        }

        return "```\n".implode("\n", $rows)."\n```";
    }

    private function codeRow(int $line, string $code, bool $culprit): string
    {
        return sprintf('%s %5d | %s', $culprit ? '>' : ' ', $line, rtrim($code));
    }

    private function mcpFallback(Project $project, IssueGroup $group, string $permalink): string
    {
        $id = $group->getKey();
        $slug = $project->slug;

        return <<<TXT
            Help me fix Watchtower issue #{$id} in project "{$slug}". No events are stored yet.

            Pull live data via the `watchtower` MCP server:
              1. get_issue(issue_id={$id})  — summary, status, counts, permalink
              2. list_events(group_id={$id}, per_page=1)  — latest event id
              3. get_event(<that id>)  — full payload (stacktrace, breadcrumbs, request)

            Reference: {$permalink}
            TXT;
    }
}
