<?php

namespace App\Contracts\V2;

use App\Models\ContentPage;

/**
 * 人物 / 场景名称来源（轻量接口，不是 DAM）。
 *
 * Foundation 目前没有正式的 Character / Scene 表，因此默认实现为空：
 * 页面单元返回空 characters / 空 scene，批次资产摘要为空数组。
 *
 * 后续若接入正式资产索引（例如 C06），只需在容器中把本接口绑定到真实实现，
 * ImageBatchAssetResolver 与批次服务无需任何改动。
 *
 * 刻意不建设：复杂 DAM / Character CRUD / Scene CRUD。
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
