<?php

namespace Database\Factories;

use App\Enums\ImageBatchRunMode;
use App\Enums\ImageBatchRunStatus;
use App\Models\ImageBatchDraft;
use App\Models\ImageBatchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImageBatchRun> */
class ImageBatchRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'image_batch_draft_id' => ImageBatchDraft::factory(),
            'mode' => ImageBatchRunMode::Full,
            'unit_ids' => [],
            'status' => ImageBatchRunStatus::Queued,
            'instruction_snapshot' => [
                'batch_instruction' => '',
                'batch_note' => null,
                'pages' => [],
                'assets' => [],
            ],
            'created_at' => now(),
        ];
    }
}
