<?php

namespace Database\Factories;

use App\Enums\CopyStatus;
use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentItem> */
class ContentItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'content_column_id' => fn (array $attributes): int => ContentColumn::factory()
                ->create(['project_id' => $attributes['project_id']])->id,
            'topic_id' => fn (array $attributes): int => Topic::factory()->create([
                'project_id' => $attributes['project_id'],
                'content_column_id' => $attributes['content_column_id'],
            ])->id,
            'title' => fake()->sentence(6),
            'copy_status' => CopyStatus::NotStarted,
        ];
    }
}
