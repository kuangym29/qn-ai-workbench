<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetSlotResource extends JsonResource
{
    public function __construct($resource, private readonly ?int $pinnedRevisionId, private readonly bool $includeHistory = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $versions = $this->resource->versions->sortByDesc('version_no')->values();
        $current = $versions->firstWhere('copy_revision_id', $this->pinnedRevisionId);
        $data = [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'production_task_id' => $this->production_task_id,
            'content_page_id' => $this->content_page_id,
            'role' => $this->role->value,
            'version_count' => $versions->count(),
            'current_version' => $current ? (new AssetVersionResource($current))->resolve($request) : null,
            'latest_version' => $versions->isNotEmpty() ? (new AssetVersionResource($versions->first()))->resolve($request) : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
        if ($this->includeHistory) {
            $data['versions'] = $versions->map(fn ($version) => (new AssetVersionResource($version))->resolve($request))->all();
        }

        return $data;
    }
}
