<?php

namespace App\Services\V2;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Exceptions\ReusableAssetNameConflictException;
use App\Models\Project;
use App\Models\ReusableAsset;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * 复用资产索引的轻量写入 / 读取服务（B06 + P5-I02）。
 *
 * 对内（后续 ImageBatch / Work 回传 / 资产建立流程）：
 *   markExisting(...)    —— 登记或更新为「已有稳定母版」
 *   markMissing(...)     —— 登记或更新为「缺失」
 *   touchLastUsed(...)   —— 更新最近被正式内容使用的时间
 *
 * 公开（P5-I02 轻量资产管理入口）：
 *   create(...)          —— 新增一条人物 / 场景（(type, name) 冲突 → 显式 422，不偷偷更新）
 *   update(...)          —— 只改 status / thumbnail_url / description（显式 null = 清空）
 *   findInProject(...)   —— 按 Project 作用域解析资产（跨 Project → 404）
 *
 * 刻意不提供：DELETE / rename / change type / 上传 / DAM / 文件夹 / 标签 / 版本 / 审批。
 *
 * 规则：
 *   1. 唯一键 = (project_id, type, name)：内部 mark 幂等；公开 create 冲突显式报错；
 *   2. 可写元信息只有 thumbnail_url / description / last_used_at 三项，
 *      其它键一律拒绝（避免偷偷塞进 prompt / model / seed / owner 等字段）；
 *   3. 内部 mark 的 null 表示「不修改」（既有语义，保持不变）；
 *      公开 update 的 null 表示「清空」（另建方法，不改变 mark 语义；
 *      ImageBatch / 旧测试不受影响）；
 *   4. touchLastUsed 只更新已登记的条目，不存在则返回 null（使用记录不会凭空造出索引条目）；
 *   5. last_used_at 是系统字段：create / update 都不写它，只有正式 touch 流程才更新。
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
     * 按 Project 作用域解析资产。跨 Project 的 id → ModelNotFoundException（HTTP 404）。
     *
     * 公开 PATCH 必须经此解析，禁止「只按 asset id 全局更新」。
     */
    public function findInProject(Project $project, int|string $id): ReusableAsset
    {
        return ReusableAsset::query()
            ->where('project_id', $project->id)
            ->findOrFail($id);
    }

    /**
     * 公开创建：新增一条人物 / 场景索引条目。
     *
     * (project_id, type, name) 已存在 → ReusableAssetNameConflictException（Controller 映射 422）；
     * 并发创建同一身份时，数据库唯一约束作为安全网兜底（捕获后同样映射为冲突，不会 500）。
     *
     * last_used_at 是系统字段，create 不写它（新条目一律「未使用」）。
     */
    public function create(
        Project $project,
        ReusableAssetType|string $type,
        string $name,
        ReusableAssetStatus|string $status,
        ?string $thumbnailUrl = null,
        ?string $description = null,
    ): ReusableAsset {
        $resolvedType = $this->resolveType($type);
        $resolvedStatus = $this->resolveStatus($status);
        $normalizedName = $this->normalizeName($name);

        // 显式重复检查：不偷偷更新既有记录，直接冲突。
        $exists = ReusableAsset::query()
            ->where('project_id', $project->id)
            ->where('type', $resolvedType->value)
            ->where('name', $normalizedName)
            ->exists();

        if ($exists) {
            throw new ReusableAssetNameConflictException($resolvedType, $normalizedName);
        }

        try {
            return ReusableAsset::query()->create([
                'project_id' => $project->id,
                'type' => $resolvedType->value,
                'name' => $normalizedName,
                'status' => $resolvedStatus->value,
                'thumbnail_url' => $thumbnailUrl,
                'description' => $description,
            ]);
        } catch (QueryException $e) {
            // 并发竞争兜底：唯一约束触发时同样按「同名已存在」处理（不 500）。
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            throw new ReusableAssetNameConflictException($resolvedType, $normalizedName);
        }
    }

    /**
     * 公开编辑：只允许改 status / thumbnail_url / description。
     *
     * 与内部 mark 的「null = 不修改」不同，这里 null 表示「主动清空」（用于清预览图 / 说明）。
     * 因为语义不同，故独立成本方法；mark 语义保持不变。
     *
     * name / type 是稳定身份，本方法不接受，也不写 last_used_at。
     *
     * @param  array{status?: ReusableAssetStatus|string, thumbnail_url?: string|null, description?: string|null}  $attributes
     */
    public function update(Project $project, ReusableAsset $asset, array $attributes): ReusableAsset
    {
        // 只允许三键（Controller 已挡，这里再防御一次）。
        foreach (array_keys($attributes) as $key) {
            if (! in_array($key, ['status', 'thumbnail_url', 'description'], true)) {
                throw new InvalidArgumentException("不支持的资产更新字段：{$key}。只允许 status / thumbnail_url / description。");
            }
        }

        // 作用域防御：资产必须属于该 Project。
        if ((int) $asset->project_id !== (int) $project->id) {
            throw new InvalidArgumentException('复用资产不属于当前 Project。');
        }

        if (array_key_exists('status', $attributes)) {
            $asset->status = $this->resolveStatus($attributes['status']);
        }

        if (array_key_exists('thumbnail_url', $attributes)) {
            $asset->thumbnail_url = $attributes['thumbnail_url'];
        }

        if (array_key_exists('description', $attributes)) {
            $asset->description = $attributes['description'];
        }

        if ($asset->isDirty()) {
            $asset->save();
        }

        return $asset;
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

    private function resolveStatus(ReusableAssetStatus|string $status): ReusableAssetStatus
    {
        if ($status instanceof ReusableAssetStatus) {
            return $status;
        }

        $resolved = ReusableAssetStatus::tryFrom($status);

        if ($resolved === null) {
            throw new InvalidArgumentException("不支持的资产状态：{$status}。只允许 existing / missing。");
        }

        return $resolved;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        if (in_array($code, ['23000', '23505', '1062', '2067', '2627'], true)) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'reusable_assets_project_type_name_unique')
            || str_contains($message, 'UNIQUE constraint failed');
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
