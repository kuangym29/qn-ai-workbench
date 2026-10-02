<?php

namespace App\Http\Requests;

use App\Enums\VideoStatus;
use Illuminate\Validation\Rule;

/**
 * DEV-W05 — 更新渠道视频状态。
 *
 * 只接受 `video_status`。publish_status / scheduled_at / published_at / project_id /
 * production_task_id / channel 一律 prohibited —— 四个状态维度相互独立，视频推进
 * 不得联动发布。
 */
class UpdateChannelVideoRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return [
            'video_status' => ['required', Rule::enum(VideoStatus::class)],
            ...$this->prohibitExcept(['video_status']),
        ];
    }
}
