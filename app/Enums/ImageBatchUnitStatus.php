<?php

namespace App\Enums;

/**
 * 图片批次 —— 单页生产状态。
 *
 * 只保留四个状态，不做审核 / 工作流：
 *   pending        待制作
 *   generated      已生成
 *   needs_revision 需修改
 *   approved       已通过
 */
enum ImageBatchUnitStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case NeedsRevision = 'needs_revision';
    case Approved = 'approved';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待制作',
            self::Generated => '已生成',
            self::NeedsRevision => '需修改',
            self::Approved => '已通过',
        };
    }
}
