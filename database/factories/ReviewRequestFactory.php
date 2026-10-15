<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ReviewRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewRequest> */
class ReviewRequestFactory extends Factory
{
    protected $model = ReviewRequest::class;

    public function definition(): array
    {
        // 显式创建关联，返回纯 int 主键，避免 factory 闭包间相互引用导致外键值被解析成模型/子查询。
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial,
            'format' => ContentFormat::ImagePost,
        ]);

        return [
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'content_branch_id' => $branch->id,
            'review_target_type' => ReviewTargetType::ImagePostCopy,
            'review_target_label' => '小绿书内容',
            'version_label' => 'v1',
            'preview_summary' => '一句话可读摘要',
            'preview' => ['kind' => 'items', 'items' => [['label' => '正文', 'value' => '示例内容']]],
            'status' => ReviewStatus::Pending,
            'submitted_at' => now(),
            'decided_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Approved,
            'decided_at' => now(),
        ]);
    }

    public function changesRequested(): static
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::ChangesRequested,
            'decided_at' => now(),
        ]);
    }
}
