<?php

namespace Database\Factories;

use App\Models\ContentColumn;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentColumn> */
class ContentColumnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(3, true),
            'slug' => Str::lower(Str::random(12)),
            'description' => null,
            'sort_order' => 0,
        ];
    }
}
