<?php

namespace App\Services\V2;

use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Models\ContentPage;

/**
 * 空引用读取器：返回空引用，绝不从正文猜人名 / 场景。
 *
 * P5-I03 起生产容器已绑定 ContentPageReusableAssetReferenceReader（显式引用真源），
 * 本类不再进入生产容器，仅保留为测试 / fallback 桩使用。
 */
class EmptyAssetReferenceReader implements ReusableAssetReferenceReader
{
    public function names(ContentPage $page): array
    {
        return [];
    }
}
