<?php

namespace App\Exceptions;

use App\Enums\ReusableAssetType;
use RuntimeException;

/**
 * 同一 Project 下已存在相同 (type, name) 的复用资产。
 *
 * name + type 是复用资产的稳定身份：创建冲突时不得悄悄更新既有条目，
 * 必须显式报错（HTTP 422），由用户改用「查看」编辑既有母版。
 *
 * 注意：内部 markExisting / markMissing 的幂等语义不受影响 ——
 * 本异常只由公开 create() 抛出。
 */
class ReusableAssetNameConflictException extends RuntimeException
{
    public function __construct(
        public readonly ReusableAssetType $type,
        public readonly string $name,
    ) {
        parent::__construct(match ($type) {
            ReusableAssetType::Character => "同名人物已存在（{$name}）。",
            ReusableAssetType::Scene => "同名场景已存在（{$name}）。",
        });
    }
}
