<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'asset_id' => $this->asset_id,
            'copy_revision_id' => $this->copy_revision_id,
            'copy_revision_no' => $this->copyRevision->revision_no,
            'version_no' => $this->version_no,
            'note' => $this->note,
            'file' => (new AssetFileResource($this->file))->resolve($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
