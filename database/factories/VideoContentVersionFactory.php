<?php

namespace Database\Factories;

use App\Models\VideoContent;
use App\Models\VideoContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoContentVersion> */
class VideoContentVersionFactory extends Factory
{
    protected $model = VideoContentVersion::class;

    public function definition(): array
    {
        return [
            'video_content_id' => VideoContent::factory(),
            'version_no' => 1,
            'snapshot' => ['batch_instruction' => '', 'narration' => '', 'units' => []],
        ];
    }
}
