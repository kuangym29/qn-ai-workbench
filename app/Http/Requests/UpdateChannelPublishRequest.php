<?php

namespace App\Http\Requests;

use App\Enums\PublishStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * DEV-W05 — 更新渠道发布状态 / 排期 / 实际发布时间。
 *
 * 只接受 `publish_status`，以及按目标状态可选的 `scheduled_at` / `published_at`：
 *
 * - scheduled  → scheduled_at required（排期时间必填），published_at prohibited
 * - published  → published_at nullable（缺省由服务端填 now()），scheduled_at prohibited
 * - unpublished→ 两个时间都不接受，由服务端统一清空
 *
 * 注意 `published` 禁止提交 scheduled_at：服务端会把库里已有的 scheduled_at 原样保留为
 * 排期历史，客户端不能借发布请求改写它。其余归属字段与状态字段一律 prohibited。
 */
class UpdateChannelPublishRequest extends ProductionApiRequest
{
    public function rules(): array
    {
        return [
            'publish_status' => ['required', Rule::enum(PublishStatus::class)],
            'scheduled_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
            ...$this->prohibitExcept(['publish_status', 'scheduled_at', 'published_at']),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = $this->input('publish_status');

            if (! is_string($status)) {
                return;
            }

            if ($status === PublishStatus::Scheduled->value) {
                // 排期必须给出时间；同时禁止夹带 published_at。
                if ($this->input('scheduled_at') === null) {
                    $validator->errors()->add('scheduled_at', 'A scheduled publish requires scheduled_at.');
                }
                if ($this->input('published_at') !== null) {
                    $validator->errors()->add('published_at', 'published_at is not accepted when scheduling.');
                }

                return;
            }

            if ($status === PublishStatus::Published->value) {
                // 发布是终态入口：排期时间只由服务端从库里保留，客户端不得在发布请求里改写。
                if ($this->input('scheduled_at') !== null) {
                    $validator->errors()->add('scheduled_at', 'scheduled_at is not accepted when publishing; an existing schedule is kept as history.');
                }

                return;
            }

            if ($status === PublishStatus::Unpublished->value) {
                // 取消排期 / 回到未发布：时间由服务端统一清空，不接受客户端伪造。
                foreach (['scheduled_at', 'published_at'] as $field) {
                    if ($this->input($field) !== null) {
                        $validator->errors()->add($field, "{$field} is not accepted when clearing the publish state.");
                    }
                }
            }
        });
    }
}
