<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DEV-W05 — ChannelTask 对外视图。
 *
 * 渠道任务只引用共享 Production，不复制篇目与文案。`is_production_copy_current`
 * 的判定严格依据：父 ProductionTask 的 `copy_revision_id` 是否等于该 ContentItem
 * 当前最大 `revision_no` 对应的 ContentCopyRevision.id。
 *
 * 明确不依据 `copy_status`、`video_status`、`publish_status` 或 `created_at` 推断
 * current —— 一个只保存了新草稿（copy_status 变 editing）但未确认 Revision 2 的篇目，
 * 其当前正式 Revision 仍是 Revision 1，Production 依然是 current。
 */
class ChannelTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $production = $this->productionTask;
        $revision = $production?->copyRevision;
        $item = $production?->contentItem;
        $current = $item?->contentCopyRevisions()->orderByDesc('revision_no')->first();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'production_task_id' => $this->production_task_id,
            'channel' => $this->channel->value,
            'video_status' => $this->video_status->value,
            'publish_status' => $this->publish_status->value,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'production_copy_revision_id' => $production?->copy_revision_id,
            'production_copy_revision_no' => $revision?->revision_no,
            'artwork_status' => $production?->artwork_status->value,
            'is_production_copy_current' => $current !== null && $revision !== null && $current->id === $revision->id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
