<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelAssetBindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'production_task_id' => $this->production_task_id,
            'channel_task_id' => $this->channel_task_id,
            'content_page_id' => $this->content_page_id,
            'asset_id' => $this->asset_id,
            'asset_version_id' => $this->asset_version_id,
            'copy_revision_id' => $this->copy_revision_id,
            'copy_revision_no' => $this->copyRevision->revision_no,
            'binding_no' => $this->binding_no,
            'asset_version' => (new AssetVersionResource($this->assetVersion))->resolve($request),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
