<?php

namespace App\Enums;

/**
 * 批次资产检查类型（轻量聚合，不做 DAM）：人物 / 场景。
 */
enum AssetCheckType: string
{
    case Character = 'character';
    case Scene = 'scene';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Character => '人物',
            self::Scene => '场景',
        };
    }
}
