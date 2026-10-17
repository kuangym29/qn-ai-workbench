<?php

namespace App\Contracts\V2;

use App\Models\ContentPage;

/**
 * 「某一页实际引用了哪些人物 / 场景」的显式读取器。
 *
 * 刻意不做从正文里猜人名 / 猜场景的启发式实现——那会产生不可信数据。
 * 谁显式引用、引用了什么，只能由明确的数据来源回答。
 *
 * P5-I03 起正式实现为 ContentPageReusableAssetReferenceReader：
 * 数据来源是 content_page_reusable_asset_references（用户在 ImageBatch 里显式选择）。
 * 没有显式引用就返回空数组。
 */
interface ReusableAssetReferenceReader
{
    /**
     * @return list<array{type: string, name: string}>
     */
    public function names(ContentPage $page): array;
}
