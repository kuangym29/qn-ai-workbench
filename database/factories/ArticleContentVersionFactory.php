<?php

namespace Database\Factories;

use App\Models\ArticleContent;
use App\Models\ArticleContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArticleContentVersion> */
class ArticleContentVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'article_content_id' => ArticleContent::factory(),
            'version_no' => 1,
            'snapshot' => ['headline' => fake()->sentence(4), 'summary' => fake()->sentence(10)],
        ];
    }
}
