<?php

namespace App\Contracts\V2;

use App\Models\ContentPage;

/**
 * 人物 / 场景名称来源（轻量接口，不是 DAM）。
 *
 * 默认实现 ReusableAssetNameSource（配合默认 EmptyAssetReferenceReader）返回空引用，
 * 因此页面单元返回空 characters / 空 scene，批次资产摘要为空数组。
 * 后续若接入正式「页面 → 资产」显式登记（例如页面标注、unit setting 引用），
 * 只需在容器中把 ReusableAssetReferenceReader 绑定到真实实现，
 * ImageBatchAssetResolver 与批次服务无需任何改动。
 *
 * 刻意不建设：复杂 DAM / Character CRUD / Scene CRUD / 从正文猜人名场景的启发式。
 */
interface AssetNameSource
{
    /**
     * 某一页引用到的人物 / 场景。
     *
     * @return list<array{type: string, name: string, status: string}>
     *                                                                 type   = character | scene
     *                                                                 status = existing | missing | new_in_batch
     */
    public function references(ContentPage $page): array;
}
