<?php

namespace App\Services\V2;

use App\Enums\ReusableAssetType;
use App\Models\ContentPage;
use App\Models\ContentPageReusableAssetReference;
use App\Models\ReusableAsset;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * P5-I03：页面显式资产引用服务（ContentPage → ReusableAsset）。
 *
 * 这是「这一页明确使用了哪些人物、哪个场景」的唯一写入入口。
 * 引用只能来自用户显式选择：不从 final_copy 猜人物、不从 visual_evidence 猜场景、
 * 不正则抽人名、不模糊匹配、不按 description 自动关联。没选就是空引用。
 *
 * 规则：
 *   1. replace 在同一个事务内先锁当前 ContentPage，再校验 / 再原子替换
 *      （SELECT ... FOR UPDATE 必须位于事务内，否则并发替换会交错）；
 *   2. 所有资产必须属于同一 Project（asset.project_id == page.project_id），
 *      只验证「id 存在」不够，绝不跨项目绑定；
 *   3. character_asset_ids 必须全是 character；scene_asset_id 必须是 scene；
 *   4. character 去重后允许 0..N 个；scene 第一版最多 1 个；
 *   5. 稳定顺序：人物按 reusable_asset_id 升序，场景排最后；
 *   6. last_used_at 是系统字段：本服务一次都不碰它。
 *
 * 刻意不做：Repository 层 / Command bus / Workflow engine / 主角配角 /
 * 前景背景 / 角色权重 / 出现比例 / 服装版本 / 镜头位置。
 */
class ContentPageAssetReferenceService
{
    /**
     * 当前页的显式引用（稳定顺序：人物按 id 升序，场景最后）。
     *
     * @return list<array{type: string, name: string, asset_id: int}>
     */
    public function references(ContentPage $page): array
    {
        $rows = ContentPageReusableAssetReference::query()
            ->where('content_page_id', $page->id)
            ->orderBy('reusable_asset_id')
            ->get();

        $assets = ReusableAsset::query()
            ->whereIn('id', $rows->pluck('reusable_asset_id')->all())
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($rows as $row) {
            /** @var ReusableAsset|null $asset */
            $asset = $assets->get((int) $row->reusable_asset_id);
            if ($asset === null) {
                continue;
            }

            $result[] = [
                'type' => $asset->type->value,
                'name' => $asset->name,
                'asset_id' => (int) $asset->id,
            ];
        }

        // 稳定确定顺序：人物在前（按资产 id 升序），场景排最后。
        usort($result, static function (array $a, array $b): int {
            $weight = static fn (array $row): int => $row['type'] === ReusableAssetType::Scene->value ? 1 : 0;
            $diff = $weight($a) - $weight($b);

            return $diff !== 0 ? $diff : ($a['asset_id'] <=> $b['asset_id']);
        });

        return $result;
    }

    /**
     * 前端编辑身份：当前页引用了哪些资产 id（稳定顺序同上）。
     *
     * @return array{character_asset_ids: list<int>, scene_asset_id: int|null}
     */
    public function referenceIds(ContentPage $page): array
    {
        $references = $this->references($page);

        return [
            'character_asset_ids' => array_values(array_map(
                static fn (array $row): int => $row['asset_id'],
                array_filter($references, static fn (array $row): bool => $row['type'] === ReusableAssetType::Character->value),
            )),
            'scene_asset_id' => $this->sceneAssetId($references),
        ];
    }

    /**
     * 原子替换当前页的显式引用。
     *
     * 事务边界（P5-I03-R1 修正）：页面锁必须在事务内取得。原先把
     * lockForUpdate() 放在 DB::transaction() 之外，自动提交模式下锁拿到即释放，
     * 并发 replace 会交错成「半套引用 / 混合人物场景 / 重复行」。现在完整顺序为：
     *   transaction begin → SELECT ... FOR UPDATE → 校验 → delete → insert → commit。
     * 纯内存归一（去重 / 转型 / 人物场景互斥）留在事务外，不占锁时间。
     *
     * 被 ImageBatchWorkspaceService::saveDraft() 调用时落在外层事务内（savepoint），
     * 独立调用时自成事务，两种路径都正确。
     *
     * @param  list<int|string>  $characterAssetIds
     * @return array{character_asset_ids: list<int>, scene_asset_id: int|null}
     */
    public function replace(ContentPage $page, array $characterAssetIds, ?int $sceneAssetId): array
    {
        // 1. 去重 + 归一（[5,5,5] 不得产生重复关系）—— 纯内存，事务外完成。
        $characterIds = $this->normalizeIds($characterAssetIds);
        $sceneId = $sceneAssetId === null ? null : (int) $sceneAssetId;

        // 2. 同一资产不得同时是人物和场景 —— 纯内存校验，事务外完成。
        if ($sceneId !== null && in_array($sceneId, $characterIds, true)) {
            throw new InvalidArgumentException('同一个资产不能同时作为人物和场景引用。');
        }

        return DB::transaction(function () use ($page, $characterIds, $sceneId): array {
            // 3. 事务内锁定当前 ContentPage：并发替换在此串行化，
            //    后到者必须看到前一次 replace 的完整结果再整体替换。
            $locked = ContentPage::query()->whereKey($page->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new InvalidArgumentException('页面不存在或已删除。');
            }

            // 4. 同一事务内完成资产校验（跨项目 / 不存在 / 类型不符一律 422）。
            $assets = $this->resolveAssets($locked, [...$characterIds, ...($sceneId === null ? [] : [$sceneId])]);

            foreach ($characterIds as $id) {
                $asset = $assets->get($id);
                if ($asset === null || $asset->type !== ReusableAssetType::Character) {
                    throw new InvalidArgumentException("人物引用不存在或不是人物资产：{$id}。");
                }
            }

            if ($sceneId !== null) {
                $scene = $assets->get($sceneId);
                if ($scene === null || $scene->type !== ReusableAssetType::Scene) {
                    throw new InvalidArgumentException("场景引用不存在或不是场景资产：{$sceneId}。");
                }
            }

            // 5. 同一事务内原子替换：先删后插，绝不留半套引用。
            ContentPageReusableAssetReference::query()->where('content_page_id', $locked->id)->delete();

            foreach ([...$characterIds, ...($sceneId === null ? [] : [$sceneId])] as $id) {
                ContentPageReusableAssetReference::query()->create([
                    'content_page_id' => $locked->id,
                    'reusable_asset_id' => $id,
                ]);
            }

            return ['character_asset_ids' => $characterIds, 'scene_asset_id' => $sceneId];
        });
    }

    /**
     * 按 Project 作用域解析资产；跨 Project / 不存在一律不进结果。
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, ReusableAsset>
     */
    private function resolveAssets(ContentPage $page, array $ids)
    {
        return ReusableAsset::query()
            ->where('project_id', $page->project_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<array{type: string, name: string, asset_id: int}>  $references
     */
    private function sceneAssetId(array $references): ?int
    {
        foreach ($references as $row) {
            if ($row['type'] === ReusableAssetType::Scene->value) {
                return $row['asset_id'];
            }
        }

        return null;
    }
}
