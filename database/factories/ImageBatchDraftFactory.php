<?php

namespace Database\Factories;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ImageBatchDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImageBatchDraft> */
class ImageBatchDraftFactory extends Factory
{
    public function definition(): array
    {
        $branch = ContentBranch::factory()->create();
        $item = ContentItem::findOrFail($branch->content_item_id);

        return [
            'project_id' => $branch->project_id,
            'content_item_id' => $item->id,
            'content_branch_id' => $branch->id,
            'batch_instruction' => '',
            'batch_note' => null,
        ];
    }
}
