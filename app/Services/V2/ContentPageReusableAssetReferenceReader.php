<?php

namespace App\Services\V2;

use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Models\ContentPage;

/**
 * P5-I03：显式引用读取器的正式实现。
 *
 * 数据来源只有 content_page_reusable_asset_references（用户在 ImageBatch 里显式选的那些）。
 * 没有显式引用就返回空数组——绝不从 final_copy 猜人物、绝不从 visual_evidence 猜场景。
 *
 * 输出合同严格保持 ReusableAssetReferenceReader 的形状：
 *   [{type: "character", name: "妈妈"}, {type: "scene", name: "客厅"}]
 * 不返回 id / status / thumbnail_url（Reader 合同不扩张）；
 * name / type / status 实时来自 reusable_assets（P5-I02 已冻结 name / type 不可修改）。
 */
class ContentPageReusableAssetReferenceReader implements ReusableAssetReferenceReader
{
    public function __construct(private readonly ContentPageAssetReferenceService $references) {}

    /**
     * @return list<array{type: string, name: string}>
     */
    public function names(ContentPage $page): array
    {
        return array_map(
            static fn (array $row): array => ['type' => $row['type'], 'name' => $row['name']],
            $this->references->references($page),
        );
    }
}
