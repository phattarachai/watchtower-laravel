<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns\ScopesToProject;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Support\SinceFilter;

#[Name('list_events')]
#[Description('List raw events (individual exception captures) for this project, newest first. Use to inspect what is arriving from SDKs, often filtered by group_id to see every event for one issue.')]
class ListEvents extends Tool
{
    use ScopesToProject;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'environment' => $schema->string()->description('Filter by environment name (e.g. "production").'),
            'release' => $schema->string()->description('Filter by release identifier.'),
            'level' => $schema->string()
                ->enum(['debug', 'info', 'warning', 'error', 'fatal'])
                ->description('Filter by severity.'),
            'group_id' => $schema->integer()->description('Filter to events belonging to one issue group.'),
            'since' => $schema->string()->description('ISO timestamp or shorthand like "15m", "6h", "7d".'),
            'page' => $schema->integer()->description('Page number (defaults to 1).'),
            'per_page' => $schema->integer()->description('Events per page (1–100, default 25). Pass per_page=1 with group_id to grab just the latest event.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate([
            'environment' => 'sometimes|string|max:64',
            'release' => 'sometimes|string|max:64',
            'level' => 'sometimes|in:debug,info,warning,error,fatal',
            'group_id' => 'sometimes|integer|min:1',
            'since' => 'sometimes|string|max:32',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $since = SinceFilter::parse($args['since'] ?? null);

        $paginator = Event::query()
            ->where('project_id', $this->projectId())
            ->orderByDesc('received_at')
            ->when($args['environment'] ?? null, fn (Builder $query, string $value) => $query->where('environment', $value))
            ->when($args['release'] ?? null, fn (Builder $query, string $value) => $query->where('release', $value))
            ->when($args['level'] ?? null, fn (Builder $query, string $value) => $query->where('level', $value))
            ->when($args['group_id'] ?? null, fn (Builder $query, int $value) => $query->where('group_id', $value))
            ->when($since, fn (Builder $query, $timestamp) => $query->where('received_at', '>=', $timestamp))
            ->paginate(perPage: $args['per_page'] ?? 25, page: $args['page'] ?? 1);

        return Response::structured([
            'events' => $paginator->getCollection()->map($this->summary(...))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Event $event): array
    {
        return [
            'id' => (string) $event->getKey(),
            'group_id' => (int) $event->group_id,
            'received_at' => $event->received_at->toIso8601String(),
            'level' => (string) $event->level,
            'environment' => $event->environment,
            'release' => $event->release,
            'sdk_name' => $event->sdk_name,
            'exception' => $this->exception($event),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exception(Event $event): ?array
    {
        $values = data_get($event->payload, 'exception.values.0');

        if (! is_array($values)) {
            return null;
        }

        return [
            'type' => $values['type'] ?? null,
            'value' => $values['value'] ?? null,
            'top_frame' => $this->topFrame((array) data_get($values, 'stacktrace.frames', [])),
        ];
    }

    /**
     * Sentry orders frames innermost-last, so the interesting frame is the last
     * one flagged `in_app` — falling back to the last frame overall.
     *
     * @param  array<int, mixed>  $frames
     * @return array<string, mixed>|null
     */
    private function topFrame(array $frames): ?array
    {
        if ($frames === []) {
            return null;
        }

        $appFrames = array_values(array_filter(
            $frames,
            fn (mixed $frame): bool => is_array($frame) && ($frame['in_app'] ?? false) === true,
        ));

        $frame = $appFrames !== [] ? end($appFrames) : end($frames);

        if (! is_array($frame)) {
            return null;
        }

        return [
            'file' => $frame['filename'] ?? $frame['abs_path'] ?? null,
            'function' => $frame['function'] ?? null,
            'line' => isset($frame['lineno']) ? (int) $frame['lineno'] : null,
        ];
    }
}
