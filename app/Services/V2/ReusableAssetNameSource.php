<?php

namespace App\Services\V2;

use App\Contracts\V2\AssetNameSource;
use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Models\ContentPage;

/**
 * ImageBatch AssetNameSource 的「复用索引」实现（只做兼容准备，不接进 ImageBatch）。
 *
 * 数据流：
 *   页面 → ReusableAssetReferenceReader（显式引用了哪些名字）
 *        → ReusableAssetIndexService（索引里有没有）
 *        → {type, name, status}（status = existing | missing）
 *
 * 默认读取器为空，因此默认输出为空数组；
 * 后续 Integration 只需换掉读取器实现即可，不需要改这里。
 *
 * 刻意不做：new_in_batch 判定（那属于批次会话状态，未在本模块登记）。
 */
class ReusableAssetNameSource implements AssetNameSource
{
    public function __construct(
        private readonly ReusableAssetReferenceReader $reader = new EmptyAssetReferenceReader,
        private readonly ReusableAssetIndexService $index = new ReusableAssetIndexService,
    ) {}

    /**
     * @return list<array{type: string, name: string, status: string}>
     */
    public function references(ContentPage $page): array
    {
        $names = $this->reader->names($page);

        if ($names === []) {
            return [];
        }

        return $this->index->resolveNames($page->project_id, $names);
    }
}
