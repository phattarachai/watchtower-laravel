<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Server\Http\Requests\StoreProjectRequest;
use Phattarachai\WatchtowerLaravel\Server\Http\Requests\UpdateProjectRequest;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Server\Ui\ProjectPresenter;

final class UiProjectController extends Controller
{
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $name = (string) $request->validated('name');

        $project = Project::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'platform' => (string) $request->validated('platform'),
            'public_key' => Project::newPublicKey(),
            'is_active' => true,
        ]);

        return response()->json(['project' => ProjectPresenter::detail($project)], 201);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update([
            'name' => (string) $request->validated('name'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return response()->json(['project' => ProjectPresenter::detail($project->refresh())]);
    }

    public function rotateKey(Project $project): JsonResponse
    {
        $project->update(['public_key' => Project::newPublicKey()]);

        return response()->json(['project' => ProjectPresenter::detail($project->refresh())]);
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
}
