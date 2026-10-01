<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\PublishStatus;
use App\Enums\VideoStatus;
use App\Models\ChannelTask;
use App\Models\ProductionTask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChannelTask> */
class ChannelTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'production_task_id' => fn (array $attributes): int => ProductionTask::factory()
                ->create(['project_id' => $attributes['project_id']])->id,
            'channel' => Channel::WechatOfficial,
            'video_status' => VideoStatus::NotApplicable,
            'publish_status' => PublishStatus::Unpublished,
        ];
    }

    public function wechatChannels(): static
    {
        return $this->state(fn (): array => [
            'channel' => Channel::WechatChannels,
            'video_status' => VideoStatus::NotStarted,
        ]);
    }
}
