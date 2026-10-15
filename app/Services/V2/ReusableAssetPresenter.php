<?php

namespace App\Services\V2;

use App\Models\ReusableAsset;
use Illuminate\Support\Collection;

/**
 * 把索引条目渲染成 GET 契约里的固定形状：
 *   { id, type, name, status, thumbnail_url, description, last_used_at }
 *
 * key 顺序与字段名固定，前端契约不允许随内部结构漂移。
 */
class ReusableAssetPresenter
{
    /**
     * @return array{id: int, type: string, name: string, status: string, thumbnail_url: string|null, description: string|null, last_used_at: string|null}
     */
    public function item(ReusableAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'type' => $asset->type->value,
            'name' => $asset->name,
            'status' => $asset->status->value,
            'thumbnail_url' => $asset->thumbnail_url,
            'description' => $asset->description,
            'last_used_at' => $asset->last_used_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, ReusableAsset>|iterable<ReusableAsset>  $assets
     * @return list<array{id: int, type: string, name: string, status: string, thumbnail_url: string|null, description: string|null, last_used_at: string|null}>
     */
    public function collection(iterable $assets): array
    {
        $items = [];

        foreach ($assets as $asset) {
            $items[] = $this->item($asset);
        }

        return $items;
    }
}
