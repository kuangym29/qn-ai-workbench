<?php

namespace App\Services\V2;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\Project;
use App\Models\ReusableAsset;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * 复用资产索引的轻量写入 / 读取服务（B06）。
 *
 * 对内（后续 ImageBatch / Work 回传 / 资产建立流程）：
 *   markExisting(...)    —— 登记或更新为「已有稳定母版」
 *   markMissing(...)     —— 登记或更新为「缺失」
 *   touchLastUsed(...)   —— 更新最近被正式内容使用的时间
 *
 * 对外只有一个只读 GET。刻意不提供公开 CRUD / 后台管理 / 上传 / DAM。
 *
 * 规则：
 *   1. 唯一键 = (project_id, type, name)，重复 mark 幂等（不会产生第二行）；
 *   2. 可写元信息只有 thumbnail_url / description / last_used_at 三项，
 *      其它键一律拒绝（避免偷偷塞进 prompt / model / seed / owner 等字段）；
 *   3. null 表示「不修改」，已存在的值不会因为后续 mark 被清空；
 *   4. touchLastUsed 只更新已登记的条目，不存在则返回 null（使用记录不会凭空造出索引条目）。
 */
class ReusableAssetIndexService
{
    /** 允许写入的元信息白名单。 */
    private const META_KEYS = ['thumbnail_url', 'description', 'last_used_at'];

    /**
     * GET 契约整体结构：
     *   { characters: [...], scenes: [...] }
     *
     * @return array{characters: list<array{id: int, type: string, name: string, status: string, thumbnail_url: string|null, description: string|null, last_used_at: string|null}>, scenes: list<array{id: int, type: string, name: string, status: string, thumbnail_url: string|null, description: string|null, last_used_at: string|null}>}
     */
    public function index(Project $project): array
    {
        return [
            'characters' => $this->listOf($project, ReusableAssetType::Character),
            'scenes' => $this->listOf($project, ReusableAssetType::Scene),
        ];
    }

    /**
     * 某一类资产的索引列表。固定排序：existing 优先，其次 name 升序。
     *
     * @return list<array{id: int, type: string, name: string, status: string, thumbnail_url: string|null, description: string|null, last_used_at: string|null}>
     */
    public function listOf(Project $project, ReusableAssetType $type): array
    {
        $assets = ReusableAsset::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
            // MySQL 8.4 与 SQLite 都支持 CASE 排序：existing=0，missing=1。
            ->orderByRaw("CASE WHEN status = 'existing' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return (new ReusableAssetPresenter)->collection($assets);
    }

    /**
     * 登记为「已有稳定母版」。
     *
     * @param  array{thumbnail_url?: string|null, description?: string|null, last_used_at?: CarbonInterface|string|null}  $meta
     */
    public function markExisting(Project $project, ReusableAssetType|string $type, string $name, array $meta = []): ReusableAsset
    {
        return $this->mark($project, ReusableAssetStatus::Existing, $type, $name, $meta);
    }

    /**
     * 登记为「缺失」。
     *
     * @param  array{thumbnail_url?: string|null, description?: string|null, last_used_at?: CarbonInterface|string|null}  $meta
     */
    public function markMissing(Project $project, ReusableAssetType|string $type, string $name, array $meta = []): ReusableAsset
    {
        return $this->mark($project, ReusableAssetStatus::Missing, $type, $name, $meta);
    }

    /**
     * 更新「最近被正式内容使用」的时间戳。
     *
     * 只索引不造数据：条目不存在时返回 null，不创建。
     */
    public function touchLastUsed(Project $project, ReusableAssetType|string $type, string $name, ?CarbonInterface $at = null): ?ReusableAsset
    {
        $asset = $this->find($project, $type, $name);

        if ($asset === null) {
            return null;
        }

        $asset->last_used_at = $at ?? now();
        $asset->save();

        return $asset;
    }

    /**
     * 批量更新使用时间（供 ImageBatch / Work 回传调用）。
     *
     * 只看本 Project 的条目，跨 Project 的 id 一律忽略。
     *
     * @param  list<int>  $ids
     */
    public function touchLastUsedByIds(Project $project, array $ids, ?CarbonInterface $at = null): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return 0;
        }

        return ReusableAsset::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $ids)
            ->update(['last_used_at' => $at ?? now()]);
    }

    /**
     * 把一批被引用的资产名解析成索引状态。
     *
     * 索引里有 → 用它自己的 status；索引里没有 → missing（未登记 = 缺）。
     * 非法 type 或空名字直接跳过（不做启发式猜测）。
     *
     * @param  list<array{type: string, name: string}>  $references
     * @return list<array{type: string, name: string, status: string}>
     */
    public function resolveNames(Project|int $project, array $references): array
    {
        $projectId = $project instanceof Project ? $project->id : $project;

        $wanted = [];
        foreach ($references as $reference) {
            $type = ReusableAssetType::tryFrom(is_string($reference['type'] ?? null) ? $reference['type'] : '');
            $name = is_string($reference['name'] ?? null) ? trim($reference['name']) : '';

            if ($type === null || $name === '') {
                continue;
            }

            $wanted[$type->value.'|'.$name] = ['type' => $type, 'name' => $name];
        }

        if ($wanted === []) {
            return [];
        }

        $indexed = ReusableAsset::query()
            ->where('project_id', $projectId)
            ->get(['type', 'name', 'status'])
            ->mapWithKeys(static fn (ReusableAsset $asset): array => [
                $asset->type->value.'|'.$asset->name => $asset->status->value,
            ])->all();

        $resolved = [];
        foreach ($wanted as $key => $reference) {
            $resolved[] = [
                'type' => $reference['type']->value,
                'name' => $reference['name'],
                'status' => $indexed[$key] ?? ReusableAssetStatus::Missing->value,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array{thumbnail_url?: string|null, description?: string|null, last_used_at?: CarbonInterface|string|null}  $meta
     */
    private function mark(Project $project, ReusableAssetStatus $status, ReusableAssetType|string $type, string $name, array $meta): ReusableAsset
    {
        foreach (array_keys($meta) as $key) {
            if (! in_array($key, self::META_KEYS, true)) {
                throw new InvalidArgumentException("不支持的资产元信息字段：{$key}。索引只允许 ".implode(' / ', self::META_KEYS).'。');
            }
        }

        $asset = $this->locateOrNew($project, $type, $name);
        $asset->status = $status;

        foreach (self::META_KEYS as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null) {
                $asset->{$key} = $meta[$key];
            }
        }

        if (! $asset->exists || $asset->isDirty()) {
            $asset->save();
        }

        return $asset;
    }

    private function locateOrNew(Project $project, ReusableAssetType|string $type, string $name): ReusableAsset
    {
        return ReusableAsset::query()->firstOrNew([
            'project_id' => $project->id,
            'type' => $this->resolveType($type)->value,
            'name' => $this->normalizeName($name),
        ]);
    }

    private function find(Project $project, ReusableAssetType|string $type, string $name): ?ReusableAsset
    {
        return ReusableAsset::query()
            ->where('project_id', $project->id)
            ->where('type', $this->resolveType($type)->value)
            ->where('name', $this->normalizeName($name))
            ->first();
    }

    private function resolveType(ReusableAssetType|string $type): ReusableAssetType
    {
        if ($type instanceof ReusableAssetType) {
            return $type;
        }

        $resolved = ReusableAssetType::tryFrom($type);

        if ($resolved === null) {
            throw new InvalidArgumentException("不支持的资产类型：{$type}。只允许 character / scene。");
        }

        return $resolved;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('复用资产名称不能为空。');
        }

        return $name;
    }
}
