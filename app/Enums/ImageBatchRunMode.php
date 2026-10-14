<?php

namespace App\Enums;

/**
 * 图片批次执行方式：
 *   full    整批生成（后端解析为当前全部有效页面，unit_ids 忽略）
 *   trial   先做试样
 *   partial 选择部分页面生成
 */
enum ImageBatchRunMode: string
{
    case Full = 'full';
    case Trial = 'trial';
    case Partial = 'partial';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => '整批生成',
            self::Trial => '先做试样',
            self::Partial => '选择部分页面生成',
        };
    }
}
