<?php

namespace App\Enums;

/**
 * 复用资产索引的正式状态。
 *
 * 索引只需要回答两件事：有没有稳定母版（existing）、缺不缺（missing）。
 * 刻意不引入 draft / generating / reviewing / approved / archived / failed ——
 * 那些是「资产生成流程」的状态，不是「复用索引」的状态。
 */
enum ReusableAssetStatus: string
{
    case Existing = 'existing';
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Existing => '已有母版',
            self::Missing => '缺失',
        };
    }
}
