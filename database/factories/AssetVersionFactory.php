<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ContentCopyRevision;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/** @extends Factory<AssetVersion> */
class AssetVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'project_id' => fn (array $attributes): int => Asset::findOrFail($attributes['asset_id'])->project_id,
            'content_item_id' => fn (array $attributes): int => Asset::findOrFail($attributes['asset_id'])->content_item_id,
            'copy_revision_id' => fn (array $attributes): int => ContentCopyRevision::factory()->create([
                'project_id' => $attributes['project_id'], 'content_item_id' => $attributes['content_item_id'],
            ])->id,
            'file_id' => fn (array $attributes): int => File::factory()->create(['project_id' => $attributes['project_id']])->id,
            'version_no' => 1,
            'note' => null,
        ];
    }

    public function forAssetAndCopyRevision(Asset $asset, ContentCopyRevision $revision): static
    {
        if ($asset->project_id !== $revision->project_id || $asset->content_item_id !== $revision->content_item_id) {
            throw new InvalidArgumentException('Asset and copy revision must belong to the same ContentItem.');
        }

        return $this->state(fn (): array => [
            'project_id' => $asset->project_id,
            'content_item_id' => $asset->content_item_id,
            'asset_id' => $asset->id,
            'copy_revision_id' => $revision->id,
            'file_id' => File::factory()->create(['project_id' => $asset->project_id])->id,
        ]);
    }
}
