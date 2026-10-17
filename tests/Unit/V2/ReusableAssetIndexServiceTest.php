<?php

namespace Tests\Unit\V2;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Services\V2\ReusableAssetIndexService;
use App\Services\V2\ReusableAssetPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 复用资产索引服务：登记 / 更新 / 使用时间 / 读取。
 *
 * 覆盖写入白名单、幂等、唯一约束、项目隔离、排序与名称解析。
 */
class ReusableAssetIndexServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReusableAssetIndexService $service;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReusableAssetIndexService;
        $this->project = Project::factory()->create();
    }

    public function test_mark_existing_creates_index_entry(): void
    {
        $asset = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');

        $this->assertSame(ReusableAssetStatus::Existing, $asset->status);
        $this->assertSame(ReusableAssetType::Character, $asset->type);
        $this->assertSame('柠檬熊', $asset->name);
        $this->assertTrue($asset->exists);
        $this->assertDatabaseHas('reusable_assets', [
            'id' => $asset->id,
            'project_id' => $this->project->id,
            'type' => 'character',
            'name' => '柠檬熊',
            'status' => 'existing',
        ]);
    }

    public function test_mark_missing_marks_entry_as_missing(): void
    {
        $asset = $this->service->markMissing($this->project, ReusableAssetType::Scene, '海边落日');

        $this->assertSame(ReusableAssetStatus::Missing, $asset->status);
        $this->assertTrue($asset->isMissing());
        $this->assertFalse($asset->isExisting());
    }

    public function test_repeated_mark_is_idempotent(): void
    {
        $first = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $second = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $third = $this->service->markExisting($this->project, 'character', '柠檬熊');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertDatabaseCount('reusable_assets', 1);
    }

    public function test_mark_flips_status_without_creating_second_row(): void
    {
        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊', [
            'thumbnail_url' => 'https://cdn.test/lemon.png',
            'description' => '品牌主形象',
        ]);

        $flipped = $this->service->markMissing($this->project, ReusableAssetType::Character, '柠檬熊');

        $this->assertSame(ReusableAssetStatus::Missing, $flipped->status);
        $this->assertDatabaseCount('reusable_assets', 1);
        // 元信息不会因为状态切换被清空。
        $this->assertSame('https://cdn.test/lemon.png', $flipped->thumbnail_url);
        $this->assertSame('品牌主形象', $flipped->description);
    }

    public function test_mark_stores_only_limited_meta_fields(): void
    {
        $usedAt = CarbonImmutable::parse('2026-10-16 09:00:00');

        $asset = $this->service->markExisting($this->project, ReusableAssetType::Scene, '厨房夜灯', [
            'thumbnail_url' => 'https://cdn.test/kitchen.png',
            'description' => '夜厨房主场景母版',
            'last_used_at' => $usedAt,
        ]);

        $this->assertSame('https://cdn.test/kitchen.png', $asset->thumbnail_url);
        $this->assertSame('夜厨房主场景母版', $asset->description);
        $this->assertSame($usedAt->toDateTimeString(), $asset->last_used_at?->toDateTimeString());
    }

    public function test_mark_does_not_clear_existing_meta_when_not_provided(): void
    {
        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊', [
            'thumbnail_url' => 'https://cdn.test/lemon.png',
        ]);

        $again = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');

        $this->assertSame('https://cdn.test/lemon.png', $again->thumbnail_url);
    }

    public function test_mark_rejects_unknown_meta_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊', [
            'prompt' => 'a cute lemon bear',
        ]);
    }

    public function test_mark_rejects_unsupported_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->markExisting($this->project, 'prop', '临时道具');
    }

    public function test_mark_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->markExisting($this->project, ReusableAssetType::Character, '   ');
    }

    public function test_name_is_trimmed_and_treated_as_the_same_entry(): void
    {
        $first = $this->service->markExisting($this->project, ReusableAssetType::Character, ' 柠檬熊 ');
        $second = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('柠檬熊', $first->name);
    }

    public function test_same_name_character_and_scene_coexist(): void
    {
        $character = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $scene = $this->service->markMissing($this->project, ReusableAssetType::Scene, '柠檬熊');

        $this->assertNotSame($character->id, $scene->id);
        $this->assertDatabaseCount('reusable_assets', 2);
    }

    public function test_unique_project_type_name_is_enforced(): void
    {
        ReusableAsset::factory()->for($this->project)->character()->create(['name' => '柠檬熊']);

        $this->expectException(QueryException::class);

        ReusableAsset::factory()->for($this->project)->character()->create(['name' => '柠檬熊']);
    }

    public function test_project_isolation(): void
    {
        $other = Project::factory()->create();
        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $this->service->markExisting($other, ReusableAssetType::Character, '柠檬熊');

        $this->assertCount(1, $this->project->reusableAssets()->get());
        $this->assertCount(1, $other->reusableAssets()->get());

        $mine = $this->service->listOf($this->project, ReusableAssetType::Character);
        $this->assertCount(1, $mine);
        $this->assertSame('柠檬熊', $mine[0]['name']);
    }

    public function test_touch_last_used_updates_timestamp_and_is_idempotent_in_row_count(): void
    {
        $at = CarbonImmutable::parse('2026-10-16 12:30:00');
        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');

        $touched = $this->service->touchLastUsed($this->project, ReusableAssetType::Character, '柠檬熊', $at);

        $this->assertNotNull($touched);
        $this->assertSame($at->toDateTimeString(), $touched->last_used_at?->toDateTimeString());
        $this->assertDatabaseCount('reusable_assets', 1);
    }

    public function test_touch_last_used_returns_null_when_entry_absent(): void
    {
        $this->assertNull($this->service->touchLastUsed($this->project, ReusableAssetType::Scene, '未登记场景'));
        $this->assertDatabaseCount('reusable_assets', 0);
    }

    public function test_touch_last_used_by_ids_only_touches_same_project(): void
    {
        $other = Project::factory()->create();
        $mine = $this->service->markExisting($this->project, ReusableAssetType::Scene, '海边落日');
        $theirs = $this->service->markExisting($other, ReusableAssetType::Scene, '雪山清晨');

        $count = $this->service->touchLastUsedByIds($this->project, [$mine->id, $theirs->id]);

        $this->assertSame(1, $count);
        $this->assertNotNull($mine->fresh()->last_used_at);
        $this->assertNull($theirs->fresh()->last_used_at);
    }

    public function test_touch_last_used_by_ids_ignores_empty_input(): void
    {
        $this->assertSame(0, $this->service->touchLastUsedByIds($this->project, []));
    }

    public function test_index_splits_characters_and_scenes(): void
    {
        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $this->service->markMissing($this->project, ReusableAssetType::Scene, '海边落日');

        $index = $this->service->index($this->project);

        $this->assertSame(['characters', 'scenes'], array_keys($index));
        $this->assertCount(1, $index['characters']);
        $this->assertCount(1, $index['scenes']);
        $this->assertSame('character', $index['characters'][0]['type']);
        $this->assertSame('scene', $index['scenes'][0]['type']);
    }

    public function test_list_orders_existing_first_then_by_name(): void
    {
        $this->service->markMissing($this->project, ReusableAssetType::Character, 'Alpha');
        $this->service->markExisting($this->project, ReusableAssetType::Character, 'Zeta');
        $this->service->markExisting($this->project, ReusableAssetType::Character, 'Beta');
        $this->service->markMissing($this->project, ReusableAssetType::Scene, 'Atrium');

        $names = array_column($this->service->listOf($this->project, ReusableAssetType::Character), 'name');

        $this->assertSame(['Beta', 'Zeta', 'Alpha'], $names);
    }

    public function test_resolve_names_uses_index_status_and_defaults_missing(): void
    {
        $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $this->service->markMissing($this->project, ReusableAssetType::Scene, '海边落日');

        $resolved = $this->service->resolveNames($this->project, [
            ['type' => 'character', 'name' => '柠檬熊'],
            ['type' => 'scene', 'name' => '海边落日'],
            ['type' => 'character', 'name' => '未登记人物'],
            ['type' => 'prop', 'name' => '临时道具'],
            ['type' => 'scene', 'name' => '   '],
        ]);

        $this->assertSame([
            ['type' => 'character', 'name' => '柠檬熊', 'status' => 'existing'],
            ['type' => 'scene', 'name' => '海边落日', 'status' => 'missing'],
            ['type' => 'character', 'name' => '未登记人物', 'status' => 'missing'],
        ], $resolved);
    }

    public function test_resolve_names_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->service->resolveNames($this->project, []));
    }

    public function test_presenter_item_shape_is_fixed(): void
    {
        $asset = $this->service->markExisting($this->project, ReusableAssetType::Character, '柠檬熊', [
            'thumbnail_url' => 'https://cdn.test/lemon.png',
            'description' => '品牌主形象',
            'last_used_at' => CarbonImmutable::parse('2026-10-16 08:00:00'),
        ]);

        $this->assertSame(
            ['id', 'type', 'name', 'status', 'thumbnail_url', 'description', 'last_used_at'],
            array_keys((new ReusableAssetPresenter)->item($asset))
        );
    }

    // ── P5-I02 公开 create / update ─────────────────────────────────

    public function test_create_makes_character_and_scene(): void
    {
        $character = $this->service->create($this->project, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Missing);
        $scene = $this->service->create($this->project, ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing, 'https://cdn.test/living.png', '主场景');

        $this->assertTrue($character->exists);
        $this->assertSame(ReusableAssetType::Character, $character->type);
        $this->assertSame(ReusableAssetStatus::Missing, $character->status);
        $this->assertNull($character->last_used_at, 'create 不得写 last_used_at（系统字段）');

        $this->assertSame(ReusableAssetType::Scene, $scene->type);
        $this->assertSame('https://cdn.test/living.png', $scene->thumbnail_url);
        $this->assertSame('主场景', $scene->description);
    }

    public function test_create_rejects_duplicate_type_name_without_updating_existing(): void
    {
        $first = $this->service->create($this->project, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Missing);
        $first->description = '原始说明';
        $first->save();

        $thrown = null;
        try {
            $this->service->create($this->project, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing, null, '不该写入');
        } catch (\App\Exceptions\ReusableAssetNameConflictException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '重复 (type, name) 必须显式冲突');
        $this->assertSame(1, ReusableAsset::query()->where('project_id', $this->project->id)->count());
        $this->assertSame(ReusableAssetStatus::Missing, $first->fresh()->status, '既有记录不得被偷偷更新');
        $this->assertSame('原始说明', $first->fresh()->description);
    }

    public function test_create_allows_same_name_across_types(): void
    {
        $this->service->create($this->project, ReusableAssetType::Character, '办公室', ReusableAssetStatus::Missing);
        $this->service->create($this->project, ReusableAssetType::Scene, '办公室', ReusableAssetStatus::Missing);

        $this->assertSame(2, ReusableAsset::query()->where('project_id', $this->project->id)->count());
    }

    public function test_update_only_touches_status_thumbnail_and_description(): void
    {
        $asset = $this->service->create($this->project, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Missing);
        $asset->last_used_at = CarbonImmutable::parse('2026-10-16 08:00:00');
        $asset->save();

        $updated = $this->service->update($this->project, $asset, [
            'status' => ReusableAssetStatus::Existing,
            'thumbnail_url' => 'https://cdn.test/mom.png',
            'description' => '固定身份',
        ]);

        $this->assertSame(ReusableAssetStatus::Existing, $updated->status);
        $this->assertSame('https://cdn.test/mom.png', $updated->thumbnail_url);
        $this->assertSame('固定身份', $updated->description);
        $this->assertSame('妈妈', $updated->name, 'name 不可变');
        $this->assertSame(ReusableAssetType::Character, $updated->type, 'type 不可变');
        $this->assertSame('2026-10-16 08:00:00', $updated->last_used_at->toDateTimeString(), 'update 不得改 last_used_at');
    }

    public function test_update_explicit_null_clears_thumbnail_and_description(): void
    {
        $asset = $this->service->create(
            $this->project,
            ReusableAssetType::Scene,
            '客厅',
            ReusableAssetStatus::Existing,
            'https://cdn.test/living.png',
            '主场景',
        );

        $updated = $this->service->update($this->project, $asset, [
            'thumbnail_url' => null,
            'description' => null,
        ]);

        $this->assertNull($updated->thumbnail_url, '显式 null 必须清空预览图');
        $this->assertNull($updated->description, '显式 null 必须清空说明');
    }

    public function test_update_rejects_unknown_keys(): void
    {
        $asset = $this->service->create($this->project, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing);

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($this->project, $asset, ['name' => '爸爸']);
    }

    public function test_find_in_project_scopes_by_project(): void
    {
        $other = Project::factory()->create();
        $asset = $this->service->create($other, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->findInProject($this->project, $asset->id);
    }

    // ── mark 语义回归（P5-I02 不得改变） ───────────────────────────

    public function test_mark_semantics_unchanged_null_means_do_not_modify(): void
    {
        $this->service->markExisting($this->project, ReusableAssetType::Character, '妈妈', [
            'thumbnail_url' => 'https://cdn.test/mom.png',
            'description' => '固定身份',
        ]);

        // 再次 mark 且 meta 为 null：既有值不得被清空。
        $asset = $this->service->markMissing($this->project, ReusableAssetType::Character, '妈妈', [
            'thumbnail_url' => null,
            'description' => null,
        ]);

        $this->assertSame(ReusableAssetStatus::Missing, $asset->status);
        $this->assertSame('https://cdn.test/mom.png', $asset->thumbnail_url);
        $this->assertSame('固定身份', $asset->description);
        $this->assertSame(1, ReusableAsset::query()->where('project_id', $this->project->id)->count());
    }

    public function test_mark_existing_and_missing_still_idempotent_after_update_method_added(): void
    {
        $a = $this->service->markExisting($this->project, ReusableAssetType::Scene, '客厅');
        $b = $this->service->markExisting($this->project, ReusableAssetType::Scene, '客厅');

        $this->assertTrue($a->is($b));
        $this->assertSame(1, ReusableAsset::query()->where('project_id', $this->project->id)->count());
    }
}
