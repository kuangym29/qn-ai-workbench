<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $revision = $this->copyRevision;
        $current = $this->contentItem->contentCopyRevisions()->orderByDesc('revision_no')->first();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'copy_revision_id' => $this->copy_revision_id,
            'copy_revision_no' => $revision?->revision_no,
            'artwork_status' => $this->artwork_status->value,
            'is_copy_revision_current' => $current !== null && $revision !== null && $current->id === $revision->id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
