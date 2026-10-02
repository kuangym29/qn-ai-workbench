<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DEV-W07 — SourceReference 对外视图。
 *
 * 只暴露真实存在的列。特别地，这里**不**返回 file_exists / absolute_path /
 * sync_status / hash / version —— 工作台只管理路径引用，不接触文件系统。
 */
class SourceReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'content_item_id' => $this->content_item_id,
            'role' => $this->role->value,
            'authority' => $this->authority->value,
            'source_path' => $this->source_path,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
