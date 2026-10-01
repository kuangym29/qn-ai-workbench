<?php

namespace Database\Factories;

use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentCopyRevision> */
class ContentCopyRevisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'project_id' => fn (array $attributes): int => ContentItem::findOrFail($attributes['content_item_id'])->project_id,
            'revision_no' => 1,
            'confirmed_at' => now(),
        ];
    }
}
