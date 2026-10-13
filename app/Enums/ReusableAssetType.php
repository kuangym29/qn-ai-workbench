<?php

namespace App\Enums;

/**
 * 复用资产索引的资产类型。
 *
 * 刻意只允许两类：
 *   character —— 长期重复出现的固定身份（人物母版）
 *   scene     —— 稳定场景母版
 *
 * 时间 / 光线 / 天气 / 临时道具 / 人物位置都不属于 Scene Master，因此没有对应枚举值。
 */
enum ReusableAssetType: string
{
    case Character = 'character';
    case Scene = 'scene';

    public function label(): string
    {
        return match ($this) {
            self::Character => '人物',
            self::Scene => '场景',
        };
    }
}
