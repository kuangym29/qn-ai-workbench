<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\VideoContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoContent> */
class VideoContentFactory extends Factory
{
    protected $model = VideoContent::class;

    public function definition(): array
    {
        // 显式创建关联并返回纯 int 主键，避免 factory 闭包相互引用被解析成模型/子查询。
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatChannels,
            'format' => ContentFormat::Video,
        ]);

        return [
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'content_branch_id' => $branch->id,
            'batch_instruction' => '整批视频制作说明',
            'narration' => '完整旁白稿',
            'units' => [],
            'source_fingerprint' => null,
        ];
    }
}
