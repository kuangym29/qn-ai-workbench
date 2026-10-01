<?php

namespace App\Http\Controllers;

use App\Enums\CopyStatus;
use App\Http\Requests\ContentItemRequest;
use App\Http\Resources\ContentItemResource;
use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentItemController extends Controller
{
    public function index(Request $request, Project $project, int $column, int $topic, ProjectContext $context): AnonymousResourceCollection
    {
        $context->assertCurrent($request, $project);
        $parent = $project->contentColumns()->findOrFail($column)->topics()->findOrFail($topic);

        return ContentItemResource::collection($parent->contentItems()->orderBy('id')->get());
    }

    public function store(ContentItemRequest $request, Project $project, int $column, int $topic): ContentItemResource
    {
        $parent = $project->contentColumns()->findOrFail($column)->topics()->findOrFail($topic);
        $item = $parent->contentItems()->forceCreate([
            'project_id' => $project->id,
            'content_column_id' => $column,
            'copy_status' => CopyStatus::NotStarted,
            ...$request->validated(),
        ]);

        return new ContentItemResource($item);
    }

    public function show(Request $request, Project $project, int $column, int $topic, int $item, ProjectContext $context): ContentItemResource
    {
        $context->assertCurrent($request, $project);
        $parent = $project->contentColumns()->findOrFail($column)->topics()->findOrFail($topic);

        return new ContentItemResource($parent->contentItems()->findOrFail($item));
    }

    public function update(ContentItemRequest $request, Project $project, int $column, int $topic, int $item): ContentItemResource
    {
        $parent = $project->contentColumns()->findOrFail($column)->topics()->findOrFail($topic);
        $model = $parent->contentItems()->findOrFail($item);
        $model->update($request->validated());

        return new ContentItemResource($model->refresh());
    }
}
