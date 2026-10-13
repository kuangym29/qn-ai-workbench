<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArticleContent> */
class ArticleContentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'project_id' => fn (array $attributes): int => ContentItem::findOrFail($attributes['content_item_id'])->project_id,
            'content_branch_id' => fn (array $attributes): int => ContentBranch::factory()->create([
                'content_item_id' => $attributes['content_item_id'],
                'project_id' => $attributes['project_id'],
                'channel' => Channel::WechatOfficial,
                'format' => ContentFormat::Article,
            ])->id,
            'headline' => fake()->sentence(4),
            'summary' => fake()->sentence(10),
            'body' => null,
            'image_suggestions' => null,
            'cover_suggestion' => null,
        ];
    }
}
