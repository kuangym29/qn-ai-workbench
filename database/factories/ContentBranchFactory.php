<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentBranch> */
class ContentBranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'project_id' => fn (array $attributes): int => ContentItem::findOrFail($attributes['content_item_id'])->project_id,
            'channel' => Channel::WechatOfficial,
            'format' => ContentFormat::ImagePost,
            'stopped_at' => null,
        ];
    }
}
