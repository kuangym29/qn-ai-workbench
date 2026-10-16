<?php

namespace Database\Factories;

use App\Models\Inspiration;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inspiration> */
class InspirationFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'body' => fake()->sentence(), 'note' => null];
    }
}
