<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ui;

use Phattarachai\WatchtowerLaravel\Server\Models\Project;

final class ProjectPresenter
{
    /** @var list<string> */
    public const array PLATFORMS = ['laravel', 'javascript', 'nextjs', 'wordpress', 'php', 'other'];

    /**
     * @return array<string, mixed>
     */
    public static function props(): array
    {
        return [
            'projects' => Project::query()
                ->orderBy('name')
                ->get()
                ->map(self::detail(...))
                ->all(),
            'platforms' => self::PLATFORMS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Project $project): array
    {
        $dsn = $project->buildDsn((string) config('app.url', 'http://localhost'));

        return [
            ...self::option($project),
            'public_key' => (string) $project->public_key,
            'masked_key' => self::mask((string) $project->public_key),
            'retention_days' => $project->retention_days,
            'dsn' => $dsn,
            'snippets' => InstallSnippets::for($project, $dsn),
        ];
    }

    /**
     * The filter-dropdown shape — no key material, no snippets.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        return Project::query()->orderBy('name')->get()->map(self::option(...))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function option(Project $project): array
    {
        return [
            'id' => (int) $project->getKey(),
            'name' => (string) $project->name,
            'slug' => (string) $project->slug,
            'platform' => (string) $project->platform,
            'is_active' => (bool) $project->is_active,
        ];
    }

    private static function mask(string $key): string
    {
        return strlen($key) <= 8
            ? str_repeat('•', strlen($key))
            : substr($key, 0, 4).str_repeat('•', 12).substr($key, -4);
    }
}
