<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use Illuminate\Validation\Rule;

/**
 * DEV-W05 — 创建渠道任务。
 *
 * 只接受 `channel`。project_id / production_task_id / video_status / publish_status /
 * scheduled_at / published_at 全部由服务端决定，客户端提交一律 prohibited。
 */
class CreateChannelTaskRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(Channel::class)],
            ...$this->prohibitExcept(['channel']),
        ];
    }
}
