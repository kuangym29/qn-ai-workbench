<?php

namespace App\Services\V2;

use App\Contracts\V2\AssetNameSource;
use App\Enums\AssetCheckStatus;
use App\Enums\AssetCheckType;
use App\Models\ContentPage;
use Illuminate\Support\Collection;

/**
 * 图片批次资产检查（第一阶段：轻量聚合）。
 *
 * 只做三件事：
 *   1. 从名称来源读取每页引用到的人物 / 场景；
 *   2. 按整批聚合去重（保持出现顺序）；
 *   3. 同名状态冲突时按 new_in_batch > missing > existing 取最强。
 *
 * 不做：复杂 DAM、资产 CRUD、素材上传、跨项目资产库。
 */
class ImageBatchAssetResolver
{
    /** 状态强度：越大越优先。 */
    private const STATUS_WEIGHT = [
        AssetCheckStatus::Existing->value => 1,
        AssetCheckStatus::Missing->value => 2,
        AssetCheckStatus::NewInBatch->value => 3,
    ];

    public function __construct(private readonly AssetNameSource $source = new EmptyAssetNameSource) {}

    /**
     * 某一页的人物名（保持顺序、去重）。
     *
     * @return list<string>
     */
    public function characters(ContentPage $page): array
    {
        return $this->namesOfType($page, AssetCheckType::Character);
    }

    /** 某一页的场景名（无则 null）。 */
    public function scene(ContentPage $page): ?string
    {
        return $this->namesOfType($page, AssetCheckType::Scene)[0] ?? null;
    }

    /**
     * 整批资产检查摘要。
     *
     * @param  Collection<int, ContentPage>  $pages
     * @return list<array{type: string, name: string, status: string}>
     */
    public function assets(Collection $pages): array
    {
        $merged = [];

        foreach ($pages as $page) {
            foreach ($this->source->references($page) as $ref) {
                $type = is_string($ref['type'] ?? null) ? $ref['type'] : null;
                $name = is_string($ref['name'] ?? null) ? trim($ref['name']) : '';
                $status = is_string($ref['status'] ?? null) ? $ref['status'] : null;

                if ($type === null || $name === '' || $status === null) {
                    continue;
                }
                if (AssetCheckType::tryFrom($type) === null || AssetCheckStatus::tryFrom($status) === null) {
                    continue;
                }

                $key = $type.'|'.$name;
                $weight = self::STATUS_WEIGHT[$status] ?? 0;
                if (! isset($merged[$key]) || $weight > $merged[$key]['weight']) {
                    $merged[$key] = ['weight' => $weight, 'type' => $type, 'name' => $name, 'status' => $status];
                }
            }
        }

        return array_values(array_map(
            fn (array $row): array => ['type' => $row['type'], 'name' => $row['name'], 'status' => $row['status']],
            $merged
        ));
    }

    /**
     * @return list<string>
     */
    private function namesOfType(ContentPage $page, AssetCheckType $type): array
    {
        $names = [];

        foreach ($this->source->references($page) as $ref) {
            if (($ref['type'] ?? null) !== $type->value) {
                continue;
            }
            $name = is_string($ref['name'] ?? null) ? trim($ref['name']) : '';
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
