<?php

namespace Database\Factories;

use App\Enums\AssetRole;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ProductionTask;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'project_id' => fn (array $attributes): int => ContentItem::findOrFail($attributes['content_item_id'])->project_id,
            'production_task_id' => fn (array $attributes): int => ProductionTask::factory()->create([
                'project_id' => $attributes['project_id'], 'content_item_id' => $attributes['content_item_id'],
            ])->id,
            'content_page_id' => fn (array $attributes): int => ContentPage::factory()->create([
                'project_id' => $attributes['project_id'], 'content_item_id' => $attributes['content_item_id'],
            ])->id,
            'role' => AssetRole::CleanMaster,
        ];
    }

    public function cleanMaster(): static
    {
        return $this->state(fn (): array => ['role' => AssetRole::CleanMaster]);
    }

    public function copyMaster(): static
    {
        return $this->state(fn (): array => ['role' => AssetRole::CopyMaster]);
    }

    public function forProductionPage(ProductionTask $task, ContentPage $page): static
    {
        if ($task->project_id !== $page->project_id || $task->content_item_id !== $page->content_item_id) {
            throw new InvalidArgumentException('Production task and page must belong to the same ContentItem.');
        }

        return $this->state(fn (): array => [
            'project_id' => $task->project_id,
            'content_item_id' => $task->content_item_id,
            'production_task_id' => $task->id,
            'content_page_id' => $page->id,
        ]);
    }
}
