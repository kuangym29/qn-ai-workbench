<?php

namespace Database\Factories;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\ReusableAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReusableAsset> */
class ReusableAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ReusableAssetType::Character->value,
            'name' => fake()->unique()->name(),
            'status' => ReusableAssetStatus::Existing->value,
            'thumbnail_url' => null,
            'description' => null,
            'last_used_at' => null,
        ];
    }

    public function character(): static
    {
        return $this->state(fn (): array => ['type' => ReusableAssetType::Character->value]);
    }

    public function scene(): static
    {
        return $this->state(fn (): array => ['type' => ReusableAssetType::Scene->value]);
    }

    public function existing(): static
    {
        return $this->state(fn (): array => ['status' => ReusableAssetStatus::Existing->value]);
    }

    public function missing(): static
    {
        return $this->state(fn (): array => ['status' => ReusableAssetStatus::Missing->value]);
    }

    public function withThumbnail(string $url = 'https://cdn.example.test/thumbs/master.png'): static
    {
        return $this->state(fn (): array => ['thumbnail_url' => $url]);
    }
}
