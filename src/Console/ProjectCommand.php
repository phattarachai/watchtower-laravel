<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Ui\ProjectPresenter;

/**
 * Scriptable project management for standalone/dual installs — the same
 * operations the Settings screen exposes, without a browser.
 */
class ProjectCommand extends Command
{
    /** @var list<string> */
    private const array ACTIONS = ['list', 'create', 'rotate-key', 'activate', 'deactivate'];

    protected $signature = 'watchtower:project
        {action=list : list, create, rotate-key, activate or deactivate}
        {name? : Project name (create) or name/slug/id of an existing project}
        {--platform=laravel : Platform for a newly created project}';

    protected $description = 'List, create, rotate or (de)activate embedded Watchtower projects.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error("Unknown action [{$action}]. Expected one of: ".implode(', ', self::ACTIONS).'.');

            return self::FAILURE;
        }

        return match ($action) {
            'create' => $this->create(),
            'rotate-key' => $this->rotateKey(),
            'activate' => $this->setActive(true),
            'deactivate' => $this->setActive(false),
            default => $this->list(),
        };
    }

    private function list(): int
    {
        $projects = Project::query()->orderBy('id')->get();

        if ($projects->isEmpty()) {
            $this->warn('No projects yet. Create one with: php artisan watchtower:project create "My App"');

            return self::SUCCESS;
        }

        $appUrl = (string) config('app.url', 'http://localhost');

        $this->table(
            ['ID', 'Name', 'Slug', 'Platform', 'Active', 'Key', 'DSN'],
            $projects->map(fn (Project $project): array => [
                (string) $project->getKey(),
                $project->name,
                $project->slug,
                $project->platform,
                $project->is_active ? 'yes' : 'no',
                $this->mask($project->public_key),
                $project->buildDsn($appUrl),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function create(): int
    {
        $name = trim((string) ($this->argument('name') ?? ''));

        if ($name === '') {
            $name = trim((string) $this->ask('Project name'));
        }

        if ($name === '') {
            $this->error('A project name is required.');

            return self::FAILURE;
        }

        $platform = (string) $this->option('platform');

        if (! in_array($platform, ProjectPresenter::PLATFORMS, true)) {
            $this->error("Unknown platform [{$platform}]. Expected one of: ".implode(', ', ProjectPresenter::PLATFORMS).'.');

            return self::FAILURE;
        }

        $project = Project::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'platform' => $platform,
            'public_key' => Project::newPublicKey(),
            'is_active' => true,
        ]);

        $this->info("Created project [{$project->name}] (id {$project->getKey()}).");
        $this->line($project->buildDsn((string) config('app.url', 'http://localhost')));

        return self::SUCCESS;
    }

    private function rotateKey(): int
    {
        $project = $this->findProject();

        if ($project === null) {
            return self::FAILURE;
        }

        $project->update(['public_key' => Project::newPublicKey()]);

        $this->info("Rotated the key for [{$project->name}]. Update every SDK DSN:");
        $this->line($project->refresh()->buildDsn((string) config('app.url', 'http://localhost')));

        return self::SUCCESS;
    }

    private function setActive(bool $active): int
    {
        $project = $this->findProject();

        if ($project === null) {
            return self::FAILURE;
        }

        $project->update(['is_active' => $active]);

        $this->info("Project [{$project->name}] is now ".($active ? 'active' : 'inactive').'.');

        return self::SUCCESS;
    }

    private function findProject(): ?Project
    {
        $needle = trim((string) ($this->argument('name') ?? ''));

        if ($needle === '') {
            $this->error('Pass the project id, slug or name.');

            return null;
        }

        $project = Project::query()
            ->when(ctype_digit($needle), fn ($query) => $query->orWhere('id', (int) $needle))
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();

        if ($project === null) {
            $this->error("No project matches [{$needle}].");
        }

        return $project;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'project' : $base;
        $slug = $base;

        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function mask(string $key): string
    {
        return strlen($key) <= 8
            ? str_repeat('*', strlen($key))
            : substr($key, 0, 4).str_repeat('*', 8).substr($key, -4);
    }
}
