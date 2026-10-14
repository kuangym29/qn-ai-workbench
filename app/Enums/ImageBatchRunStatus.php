<?php

namespace App\Enums;

/**
 * 批次执行记录状态（保持少量）。
 *
 * 第一阶段不接真实生图：run 创建后返回 queued，绝不伪造成 completed / generated。
 */
enum ImageBatchRunStatus: string
{
    case Queued = 'queued';
    case Completed = 'completed';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => '排队中',
            self::Completed => '已完成',
            self::Failed => '失败',
        };
    }
}
