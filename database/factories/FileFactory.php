<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<File> */
class FileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'storage_disk' => 'local',
            'storage_path' => 'visual/'.fake()->uuid().'.png',
            'original_name' => 'master.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1024,
            'width' => 1080,
            'height' => 1440,
        ];
    }
}
