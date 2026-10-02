<?php

namespace Database\Factories;

use App\Enums\PageType;
use App\Models\ContentItem;
use App\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentPage> */
class ContentPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'project_id' => fn (array $attributes): int => ContentItem::findOrFail($attributes['content_item_id'])->project_id,
            'page_no' => 1,
            'page_type' => PageType::Content,
        ];
    }
}
