<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContentColumnRequest;
use App\Http\Resources\ContentColumnResource;
use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentColumnController extends Controller
{
    public function index(Request $request, Project $project, ProjectContext $context): AnonymousResourceCollection
    {
        $context->assertCurrent($request, $project);

        return ContentColumnResource::collection($project->contentColumns()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(ContentColumnRequest $request, Project $project, ProjectContext $context): ContentColumnResource
    {
        $context->assertCurrent($request, $project);
        $column = $project->contentColumns()->create($request->validated());

        return new ContentColumnResource($column);
    }

    public function show(Request $request, Project $project, int $column, ProjectContext $context): ContentColumnResource
    {
        $context->assertCurrent($request, $project);

        return new ContentColumnResource($project->contentColumns()->findOrFail($column));
    }

    public function update(ContentColumnRequest $request, Project $project, int $column, ProjectContext $context): ContentColumnResource
    {
        $context->assertCurrent($request, $project);
        $model = $project->contentColumns()->findOrFail($column);
        $model->update($request->validated());

        return new ContentColumnResource($model->refresh());
    }
}
