<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentPageVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'content_page_id' => $this->content_page_id,
            'version_no' => $this->version_no,
            'copy_revision_id' => $this->copy_revision_id,
            'page_no_snapshot' => $this->page_no_snapshot,
            'page_type_snapshot' => $this->page_type_snapshot,
            'column_label' => $this->column_label,
            'cover_title' => $this->cover_title,
            'cover_subtitle' => $this->cover_subtitle,
            'page_title' => $this->page_title,
            'page_small_text' => $this->page_small_text,
            'closing_line' => $this->closing_line,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
