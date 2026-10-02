<?php

namespace Database\Factories;

use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentPageVersion> */
class ContentPageVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_page_id' => ContentPage::factory(),
            'content_item_id' => fn (array $attributes): int => ContentPage::findOrFail($attributes['content_page_id'])->content_item_id,
            'project_id' => fn (array $attributes): int => ContentPage::findOrFail($attributes['content_page_id'])->project_id,
            'copy_revision_id' => null,
            'version_no' => 1,
            'page_title' => fake()->sentence(3),
        ];
    }
}
