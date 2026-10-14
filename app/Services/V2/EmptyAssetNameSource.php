<?php

namespace App\Services\V2;

use App\Contracts\V2\AssetNameSource;
use App\Models\ContentPage;

/**
 * 默认资产名称来源：Foundation 尚无正式 Character / Scene 表，返回空引用。
 *
 * 这里刻意不做任何「从正文里猜人名 / 猜场景」的启发式实现——
 * 那会产生不可信数据。等正式资产索引接入后替换本绑定即可。
 */
class EmptyAssetNameSource implements AssetNameSource
{
    public function references(ContentPage $page): array
    {
        return [];
    }
}
