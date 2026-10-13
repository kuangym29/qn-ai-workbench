<?php

namespace App\Http\Controllers;

use App\Http\Resources\V2ContentResource as Resource;
use App\Models\Project;
use App\Services\StartContentService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** V2 entrypoints deliberately keep the still-running legacy nested API intact. */
class V2ContentController extends Controller
{
    private function scope(Request $request, Project $project): void
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
    }

    public function workspace(Request $request, Project $project, string $page): Response
    {
        $this->scope($request, $project);

        return Inertia::render(match ($page) {
            'in-progress' => 'InProgress/Index', 'inspirations' => 'Inspirations/Index',
            'topics' => 'Topics/Pool', 'content' => 'ContentLibrary/Index', 'review' => 'ReviewInbox/Index',
        }, ['projectId' => $project->id]);
    }

    public function detailPage(Request $request, Project $project, int $item): Response
    {
        $this->scope($request, $project);
        $model = $project->contentItems()->findOrFail($item);

        return Inertia::render('ContentLibrary/Show', ['projectId' => $project->id, 'itemId' => $model->id]);
    }

    public function imagePostPage(Request $request, Project $project, int $item, int $branch): Response
    {
        $this->scope($request, $project);
        $model = $project->contentItems()->with(['contentColumn', 'topic.inspiration'])->findOrFail($item);
        $contentBranch = $model->contentBranches()->where('project_id', $project->id)->findOrFail($branch);
        abort_unless($contentBranch->format === \App\Enums\ContentFormat::ImagePost, 422, '仅图片文章分支支持此编辑器。');

        return Inertia::render('V2/ImagePostEditor', [
            'projectId' => $project->id, 'itemId' => $model->id, 'branchId' => $contentBranch->id,
            'columnName' => $model->contentColumn->name,
            'source' => [
                'topic' => $model->topic?->only(['id', 'title']),
                'inspiration' => $model->topic?->inspiration?->only(['id', 'body']),
            ],
        ]);
    }

    public function inspirations(Request $request, Project $project): JsonResponse
    {
        $this->scope($request, $project);

        return response()->json(['data' => $project->inspirations()->orderBy('id')->get()->map(Resource::inspiration(...))]);
    }

    public function saveInspiration(Request $request, Project $project, ?int $inspiration = null): JsonResponse
    {
        $this->scope($request, $project);
        $model = $inspiration === null ? null : $project->inspirations()->findOrFail($inspiration);
        $data = $request->validate(['body' => [$model ? 'sometimes' : 'required', 'required', 'string'], 'note' => ['sometimes', 'nullable', 'string']]);
        if ($model) {
            $model->update($data);
        } else {
            $model = $project->inspirations()->create($data);
        }

        return response()->json(['data' => Resource::inspiration($model->refresh())], $inspiration === null ? 201 : 200);
    }

    public function topics(Request $request, Project $project): JsonResponse
    {
        $this->scope($request, $project);

        return response()->json(['data' => $project->topics()->with(['inspiration', 'contentItem'])->orderBy('id')->get()->map(Resource::topic(...))]);
    }

    public function saveTopic(Request $request, Project $project, ?int $topic = null): JsonResponse
    {
        $this->scope($request, $project);
        $model = $topic === null ? null : $project->topics()->findOrFail($topic);
        // Resolve relationship IDs before payload validation, so foreign IDs always produce 404.
        if ($request->filled('content_column_id')) {
            $project->contentColumns()->findOrFail($request->input('content_column_id'));
        }
        if ($request->filled('inspiration_id')) {
            $project->inspirations()->findOrFail($request->input('inspiration_id'));
        }
        $required = $model ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$required, 'required', 'string', 'max:200'],
            'content_column_id' => [$required, 'required', 'integer'],
            'inspiration_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', 'in:pending,parked'], 'is_backup' => ['sometimes', 'boolean'],
        ]);
        if ($model && isset($data['content_column_id']) && (int) $data['content_column_id'] !== $model->content_column_id && $model->contentItem()->exists()) {
            throw ValidationException::withMessages(['content_column_id' => '已开始创作的选题不能更换栏目。']);
        }
        if ($model) {
            $model->forceFill($data)->save();
        } else {
            $model = $project->topics()->forceCreate($data);
        }

        return response()->json(['data' => Resource::topic($model->refresh())], $topic === null ? 201 : 200);
    }

    public function start(Request $request, Project $project, int $topic, StartContentService $service): JsonResponse
    {
        $this->scope($request, $project);
        $model = $project->topics()->findOrFail($topic);
        $data = $request->validate(['branches' => ['required', 'array', 'min:1']]);

        return response()->json(['data' => Resource::item($service->start($model, $data['branches']))], 201);
    }

    public function items(Request $request, Project $project): JsonResponse
    {
        $this->scope($request, $project);

        return response()->json(['data' => $project->contentItems()->with(['topic.inspiration', 'contentBranches'])->orderBy('id')->get()->map(Resource::item(...))]);
    }

    public function item(Request $request, Project $project, int $item): JsonResponse
    {
        $this->scope($request, $project);
        $model = $project->contentItems()->findOrFail($item);
        if ($request->isMethod('patch')) {
            $model->update($request->validate([
                'title' => ['sometimes', 'required', 'string', 'max:200'],
                'core_message' => ['sometimes', 'nullable', 'string'], 'story_line' => ['sometimes', 'nullable', 'string'],
            ]));
        }

        return response()->json(['data' => Resource::item($model->refresh())]);
    }

    public function addBranch(Request $request, Project $project, int $item, StartContentService $service): JsonResponse
    {
        $this->scope($request, $project);
        $model = $project->contentItems()->findOrFail($item);
        $data = $request->validate(['channel' => ['required', 'string'], 'format' => ['required', 'string']]);

        return response()->json(['data' => Resource::branch($service->addBranch($model, $data))]);
    }

    public function stopBranch(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        $this->scope($request, $project);
        $model = $project->contentItems()->findOrFail($item)->contentBranches()->where('project_id', $project->id)->findOrFail($branch);
        $model->stop();

        return response()->json(['data' => Resource::branch($model->refresh())]);
    }

    public function inProgress(Request $request, Project $project): JsonResponse
    {
        $this->scope($request, $project);

        return response()->json(['data' => $project->contentItems()->with(['topic.inspiration', 'contentBranches'])->orderBy('id')->get()->map(function ($item): array {
            $active = $item->contentBranches->whereNull('stopped_at')->map(Resource::branch(...))->values()->all();

            return [...Resource::item($item), 'active_branches' => $active,
                'next_action' => blank($item->core_message) || blank($item->story_line) ? '完善内容' : (count($active) ? '开始制作' : '添加制作渠道')];
        })]);
    }
}
