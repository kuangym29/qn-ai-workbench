<?php

namespace App\Services\V2;

use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Models\ContentPage;

/**
 * 默认引用读取器：Foundation 还没有「页面 → 资产」的显式登记，返回空引用。
 *
 * ReusableAssetNameSource 因此默认返回空数组，行为与 ImageBatch 现有的
 * EmptyAssetNameSource 完全一致，接入前不会改变 ImageBatch 任何结果。
 */
class EmptyAssetReferenceReader implements ReusableAssetReferenceReader
{
    public function names(ContentPage $page): array
    {
        return [];
    }
}
