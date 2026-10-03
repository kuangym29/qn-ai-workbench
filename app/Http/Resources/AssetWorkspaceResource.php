<?php

namespace App\Http\Resources;

use App\Enums\AssetRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetWorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $revision = $task->copyRevision;
        $current = $revision
            ? $task->contentItem->contentCopyRevisions()->orderByDesc('revision_no')->value('id')
            : null;
        $assets = $task->assets->keyBy(fn ($asset) => $asset->content_page_id.':'.$asset->role->value);

        return [
            'production_task_id' => $task->id,
            'copy_revision_id' => $task->copy_revision_id,
            'copy_revision_no' => $revision?->revision_no,
            'is_copy_revision_current' => $revision !== null && (int) $current === $revision->id,
            'pages' => $revision ? $revision->pageVersions->map(function ($snapshot) use ($assets, $request, $task): array {
                $slots = [];
                foreach (AssetRole::cases() as $role) {
                    $asset = $assets->get($snapshot->content_page_id.':'.$role->value);
                    $slots[$role->value] = $asset ? (new AssetSlotResource($asset, $task->copy_revision_id))->resolve($request) : null;
                }

                return [
                    'content_page_id' => $snapshot->content_page_id,
                    'page_no' => $snapshot->page_no_snapshot,
                    'page_type' => $snapshot->page_type_snapshot,
                    'assets' => $slots,
                ];
            })->all() : [],
        ];
    }
}
