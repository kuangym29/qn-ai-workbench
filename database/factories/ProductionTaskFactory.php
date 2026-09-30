<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ProductionTask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionTask> */
class ProductionTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'content_item_id' => fn (array $attributes): int => ContentItem::factory()
                ->create(['project_id' => $attributes['project_id']])->id,
            'artwork_status' => 'not_started',
        ];
    }
}
