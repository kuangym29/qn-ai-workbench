<?php

namespace App\Http\Controllers;

use App\Enums\ArtworkStatus;
use App\Enums\CopyStatus;
use App\Http\Requests\EmptyProductionRequest;
use App\Http\Requests\UpdateProductionTaskRequest;
use App\Http\Resources\ProductionTaskResource;
use App\Models\ContentItem;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionTaskController extends Controller
{
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    private function responseFor(ProductionTask $task): ProductionTaskResource
    {
        return new ProductionTaskResource($task->load(['copyRevision', 'contentItem']));
    }

    public function show(Request $request, Project $project, int $column, int $topic, int $item): ProductionTaskResource|JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = $content->productionTask;

        return $task === null ? response()->json(['data' => null]) : $this->responseFor($task);
    }

    public function store(EmptyProductionRequest $request, Project $project, int $column, int $topic, int $item): JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = DB::transaction(function () use ($content, $project) {
            $locked = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            if ($locked->productionTask()->exists()) {
                throw ValidationException::withMessages(['production' => 'Production task already exists for this content item.']);
            }
            $revision = $locked->contentCopyRevisions()->orderByDesc('revision_no')->first();
            if ($locked->copy_status !== CopyStatus::Confirmed || $revision === null) {
                throw ValidationException::withMessages(['production' => 'Confirm a formal copy revision before starting production.']);
            }

            return $locked->productionTask()->forceCreate([
                'project_id' => $project->id,
                'copy_revision_id' => $revision->id,
                'artwork_status' => ArtworkStatus::NotStarted,
            ]);
        });

        return $this->responseFor($task)->response()->setStatusCode(201);
    }

    public function update(UpdateProductionTaskRequest $request, Project $project, int $column, int $topic, int $item): ProductionTaskResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = DB::transaction(function () use ($content, $request) {
            $locked = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $task = $locked->productionTask()->lockForUpdate()->firstOrFail();
            $status = ArtworkStatus::from($request->validated('artwork_status'));
            if ($status === ArtworkStatus::Approved) {
                $current = $locked->contentCopyRevisions()->orderByDesc('revision_no')->first();
                if ($locked->copy_status !== CopyStatus::Confirmed || $current === null || $task->copy_revision_id !== $current->id) {
                    throw ValidationException::withMessages(['artwork_status' => 'Approve artwork only for the current confirmed copy revision.']);
                }
            }
            $task->update(['artwork_status' => $status]);

            return $task;
        });

        return $this->responseFor($task);
    }

    public function useCurrentCopy(EmptyProductionRequest $request, Project $project, int $column, int $topic, int $item): ProductionTaskResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = DB::transaction(function () use ($content) {
            $locked = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $task = $locked->productionTask()->lockForUpdate()->firstOrFail();
            $current = $locked->contentCopyRevisions()->orderByDesc('revision_no')->first();
            if ($locked->copy_status !== CopyStatus::Confirmed || $current === null) {
                throw ValidationException::withMessages(['production' => 'Confirm a formal copy revision before switching production.']);
            }
            if ($task->channelTasks()->exists()) {
                throw ValidationException::withMessages(['production' => 'Cannot switch copy revision while channel tasks exist.']);
            }
            if ($task->copy_revision_id === $current->id) {
                return $task;
            }
            $task->forceFill([
                'copy_revision_id' => $current->id,
                'artwork_status' => ArtworkStatus::NotStarted,
            ])->save();

            return $task;
        });

        return $this->responseFor($task);
    }
}
