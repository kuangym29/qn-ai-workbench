<?php

namespace App\Enums;

/**
 * 批次资产检查状态：已有 / 缺失 / 本批新建。
 */
enum AssetCheckStatus: string
{
    case Existing = 'existing';
    case Missing = 'missing';
    case NewInBatch = 'new_in_batch';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Existing => '已有',
            self::Missing => '缺失',
            self::NewInBatch => '本批新建',
        };
    }
}
