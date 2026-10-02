<?php

namespace Database\Factories;

use App\Enums\ArtworkStatus;
use App\Models\ContentCopyRevision;
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
            'artwork_status' => ArtworkStatus::NotStarted,
            'copy_revision_id' => null,
        ];
    }

    public function forCopyRevision(ContentCopyRevision $revision): static
    {
        return $this->state(fn (): array => [
            'project_id' => $revision->project_id,
            'content_item_id' => $revision->content_item_id,
            'copy_revision_id' => $revision->id,
        ]);
    }
}
