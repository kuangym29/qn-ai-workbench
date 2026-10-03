<?php

namespace App\Http\Controllers;

use App\Enums\AssetRole;
use App\Http\Requests\AppendAssetVersionRequest;
use App\Http\Resources\AssetSlotResource;
use App\Http\Resources\AssetVersionResource;
use App\Http\Resources\AssetWorkspaceResource;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ContentItem;
use App\Models\File;
use App\Models\Project;
use App\Support\ProjectContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    public function index(Request $request, Project $project, int $column, int $topic, int $item): AssetWorkspaceResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = $content->productionTask()->firstOrFail();
        $task->load([
            'contentItem',
            'copyRevision.pageVersions' => fn ($query) => $query->orderBy('page_no_snapshot'),
            'assets.versions.copyRevision',
            'assets.versions.file',
        ]);

        return new AssetWorkspaceResource($task);
    }

    public function show(Request $request, Project $project, int $column, int $topic, int $item, int $asset): AssetSlotResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = $content->productionTask()->firstOrFail();
        $found = $task->assets()->findOrFail($asset);
        $found->load(['versions.copyRevision', 'versions.file']);

        return new AssetSlotResource($found, $task->copy_revision_id, true);
    }

    public function store(AppendAssetVersionRequest $request, Project $project, int $column, int $topic, int $item): JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $data = $request->validated();

        try {
            $version = DB::transaction(function () use ($content, $project, $data): AssetVersion {
                $locked = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
                $task = $locked->productionTask()->lockForUpdate()->firstOrFail();
                if ($task->copy_revision_id === null) {
                    throw ValidationException::withMessages(['production' => 'Production task has no pinned copy revision.']);
                }
                $page = $locked->contentPages()->findOrFail($data['content_page_id']);
                $hasSnapshot = $locked->contentCopyRevisions()->whereKey($task->copy_revision_id)
                    ->whereHas('pageVersions', fn ($query) => $query->where('content_page_id', $page->id))->exists();
                if (! $hasSnapshot) {
                    throw ValidationException::withMessages(['content_page_id' => 'Page is not part of the production copy revision.']);
                }

                $file = File::query()->where('project_id', $project->id)
                    ->where('storage_disk', $data['storage_disk'])
                    ->where('storage_path', $data['storage_path'])->lockForUpdate()->first();
                $metadata = array_intersect_key($data, array_flip(['original_name', 'mime_type', 'size_bytes', 'width', 'height']));
                foreach (['mime_type', 'size_bytes', 'width', 'height'] as $optional) {
                    $metadata[$optional] ??= null;
                }
                foreach (['size_bytes', 'width', 'height'] as $number) {
                    if ($metadata[$number] !== null) {
                        $metadata[$number] = (int) $metadata[$number];
                    }
                }
                if ($file) {
                    foreach ($metadata as $field => $value) {
                        if ($file->{$field} !== $value) {
                            throw ValidationException::withMessages(['storage_path' => 'Storage location is already registered with different metadata.']);
                        }
                    }
                } else {
                    $file = File::query()->forceCreate([
                        'project_id' => $project->id,
                        'storage_disk' => $data['storage_disk'],
                        'storage_path' => $data['storage_path'],
                        ...$metadata,
                    ]);
                }

                $asset = $task->assets()->where('content_page_id', $page->id)
                    ->where('role', $data['role'])->lockForUpdate()->first();
                if (! $asset) {
                    $asset = Asset::query()->forceCreate([
                        'project_id' => $project->id,
                        'content_item_id' => $locked->id,
                        'production_task_id' => $task->id,
                        'content_page_id' => $page->id,
                        'role' => AssetRole::from($data['role']),
                    ]);
                }

                return $asset->versions()->create([
                    'project_id' => $project->id,
                    'content_item_id' => $locked->id,
                    'copy_revision_id' => $task->copy_revision_id,
                    'file_id' => $file->id,
                    'version_no' => ((int) $asset->versions()->max('version_no')) + 1,
                    'note' => $data['note'] ?? null,
                ]);
            });
        } catch (QueryException $error) {
            if (in_array(substr((string) $error->getCode(), 0, 2), ['23'], true)) {
                throw ValidationException::withMessages(['storage_path' => 'Asset or file registration conflicted with another request. Retry after refreshing.']);
            }
            throw $error;
        }

        return (new AssetVersionResource($version->load(['copyRevision', 'file'])))->response()->setStatusCode(201);
    }
}
