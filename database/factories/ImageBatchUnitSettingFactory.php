<?php

namespace Database\Factories;

use App\Enums\ImageBatchUnitStatus;
use App\Models\ContentPage;
use App\Models\ImageBatchDraft;
use App\Models\ImageBatchUnitSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImageBatchUnitSetting> */
class ImageBatchUnitSettingFactory extends Factory
{
    public function definition(): array
    {
        $draft = ImageBatchDraft::factory()->create();
        $page = ContentPage::factory()->create([
            'project_id' => $draft->project_id,
            'content_item_id' => $draft->content_item_id,
        ]);

        return [
            'image_batch_draft_id' => $draft->id,
            'content_page_id' => $page->id,
            'special_requirement' => null,
            'source_fingerprint' => null,
            'status' => ImageBatchUnitStatus::Pending,
        ];
    }
}
