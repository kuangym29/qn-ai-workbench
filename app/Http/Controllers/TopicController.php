<?php

namespace App\Http\Controllers;

use App\Http\Requests\TopicRequest;
use App\Http\Resources\TopicResource;
use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TopicController extends Controller
{
    public function index(Request $request, Project $project, int $column, ProjectContext $context): AnonymousResourceCollection
    {
        $context->assertCurrent($request, $project);
        $parent = $project->contentColumns()->findOrFail($column);

        return TopicResource::collection($parent->topics()->orderBy('id')->get());
    }

    public function store(TopicRequest $request, Project $project, int $column): TopicResource
    {
        $parent = $project->contentColumns()->findOrFail($column);
        $topic = $parent->topics()->forceCreate(['project_id' => $project->id, ...$request->validated()]);

        return new TopicResource($topic);
    }

    public function show(Request $request, Project $project, int $column, int $topic, ProjectContext $context): TopicResource
    {
        $context->assertCurrent($request, $project);
        $parent = $project->contentColumns()->findOrFail($column);

        return new TopicResource($parent->topics()->findOrFail($topic));
    }

    public function update(TopicRequest $request, Project $project, int $column, int $topic): TopicResource
    {
        $parent = $project->contentColumns()->findOrFail($column);
        $model = $parent->topics()->findOrFail($topic);
        $model->update($request->validated());

        return new TopicResource($model->refresh());
    }
}
