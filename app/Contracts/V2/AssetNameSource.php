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
 *
 * 说明（B06）：本文件与 ImageBatch（B05）分支中的同名接口保持一致，
 * 是 B06 为实现 ReusableAssetNameSource 而准备的最小兼容副本。
 * B06 未修改 B05 任何代码，真正绑定由后续 Integration 完成。
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
