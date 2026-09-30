<?php

namespace Database\Factories;

use App\Models\ContentColumn;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Topic> */
class TopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'content_column_id' => fn (array $attributes): int => ContentColumn::factory()
                ->create(['project_id' => $attributes['project_id']])->id,
            'title' => fake()->sentence(5),
            'description' => null,
        ];
    }
}
