<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentCopyRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'revision_no' => $this->revision_no,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'page_versions' => $this->whenLoaded('pageVersions', fn () => ContentPageVersionResource::collection($this->pageVersions)),
        ];
    }
}
