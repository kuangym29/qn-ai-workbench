<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjectResource::collection(Project::query()->orderBy('id')->get());
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $project = Project::create($request->validated());

        return new ProjectResource($project);
    }

    public function current(Request $request, ProjectContext $context): array
    {
        $project = $context->current($request);

        return ['data' => $project ? (new ProjectResource($project))->resolve() : null];
    }

    public function select(Request $request, Project $project, ProjectContext $context): ProjectResource
    {
        $context->select($request, $project);

        return new ProjectResource($project);
    }

    public function update(ProjectRequest $request, Project $project, ProjectContext $context): ProjectResource
    {
        $context->assertCurrent($request, $project);
        $project->update($request->validated());

        return new ProjectResource($project->refresh());
    }
}
