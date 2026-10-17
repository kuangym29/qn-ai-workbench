<?php

namespace Tests\Feature\V2;

use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageReusableAssetReference;
use App\Models\ImageBatchRun;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\V2\ContentPageAssetReferenceService;
use App\Services\V2\ContentPageReusableAssetReferenceReader;
use App\Services\V2\ReusableAssetIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5-I03：页面显式资产引用（ContentPage → ReusableAsset）真实链路测试（零 Mock）。
 *
 * 覆盖任务第 32 节 23 项：
 *   1 无显式引用 → reader []            13 字段 omitted → 保持已有 refs
 *   2 一人物 → reader character         14 页面删除 → refs cascade
 *   3 多人物 → 稳定输出                 15 GET characters / scene 来自显式 refs
 *   4 一场景 → reader scene             16 GET assets existing / missing 聚合
 *   5 人物 + 场景                       17 同资产多页引用 → summary 去重
 *   6 重复 character ids → 无重复 row   18 last_used_at 不变化
 *   7 跨 Project asset → 422            19 queued run 不 touch last_used_at
 *   8 scene id 指向 character → 422     20 run snapshot 含资产摘要
 *   9 character ids 含 scene → 422      21 copy task 正确读取引用
 *  10 unknown asset id → 422           22 资产 identity 变 → version_label 变
 *  11 [] 清人物                        23 status-only 变 → version_label 不变
 *  12 null 清场景
 */
class ImageBatchExplicitAssetReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 用真实显式读取器替换容器默认（生产容器本就绑定它，这里显式锁定）。
        $this->app->bind(ReusableAssetReferenceReader::class, ContentPageReusableAssetReferenceReader::class);
    }

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    private function batchBranch(ContentItem $item): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::ImagePost->value,
        ]);
    }

    private function page(ContentItem $item, int $pageNo, array $extra = []): ContentPage
    {
        return ContentPage::factory()->for($item)->create(array_merge([
            'page_no' => $pageNo,
            'page_type' => 'content',
            'final_copy' => 'fc'.$pageNo,
            'visual_evidence' => 've'.$pageNo,
        ], $extra));
    }

    private function base(ContentItem $item, ContentBranch $branch, string $suffix = ''): string
    {
        return "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-batch".$suffix;
    }

    /** @return array<string, mixed> */
    private function show(ContentItem $item, ContentBranch $branch): array
    {
        return $this->getJson($this->base($item, $branch))->assertOk()->json();
    }

    /**
     * 用真实索引服务登记资产状态（不 Mock）。
     *
     * @return array<string, ReusableAsset> 以资产名为键（同类型多个条目不会互相覆盖）
     */
    private function seedIndex(Project $project, array $entries): array
    {
        /** @var ReusableAssetIndexService $index */
        $index = $this->app->make(ReusableAssetIndexService::class);
        $made = [];
        foreach ($entries as [$type, $name, $status]) {
            $made[$name] = $status === ReusableAssetStatus::Existing
                ? $index->markExisting($project, $type, $name)
                : $index->markMissing($project, $type, $name);
        }

        return $made;
    }

    /** 首个 unit 设置 id（PUT 需要）。 */
    private function firstUnitId(ContentItem $item, ContentBranch $branch): int
    {
        $body = $this->show($item, $branch);

        return (int) $body['units'][0]['id'];
    }

    /** @return array<string, mixed> */
    private function saveDraft(ContentItem $item, ContentBranch $branch, array $data): array
    {
        return $this->putJson($this->base($item, $branch), $data)->assertOk()->json();
    }

    // ── 1~5. Reader：只回答显式引用 ────────────────────────────────────────

    public function test_reader_returns_empty_without_explicit_references(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1, ['final_copy' => '妈妈在客厅给爸爸做饭', 'visual_evidence' => '厨房场景']);
        $this->acting($item);
        $this->seedIndex($item->project, [[ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing]]);

        /** @var ContentPageReusableAssetReferenceReader $reader */
        $reader = $this->app->make(ContentPageReusableAssetReferenceReader::class);
        $this->assertSame([], $reader->names($page), '没有显式引用必须返回空数组，绝不从正文猜');
    }

    public function test_reader_returns_one_character_and_one_scene(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);

        /** @var ContentPageAssetReferenceService $service */
        $service = $this->app->make(ContentPageAssetReferenceService::class);
        $service->replace($page, [$assets['妈妈']->id], $assets['客厅']->id);

        /** @var ContentPageReusableAssetReferenceReader $reader */
        $reader = $this->app->make(ContentPageReusableAssetReferenceReader::class);
        $this->assertSame([
            ['type' => 'character', 'name' => '妈妈'],
            ['type' => 'scene', 'name' => '客厅'],
        ], $reader->names($page));
    }

    public function test_reader_multiple_characters_are_stable_and_scene_comes_last(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '姐姐', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);

        /** @var ContentPageAssetReferenceService $service */
        $service = $this->app->make(ContentPageAssetReferenceService::class);
        // 乱序提交 + 重复 id：输出必须稳定（按资产 id 升序，场景最后）。
        $service->replace($page, [$assets['妈妈']->id, $assets['妈妈']->id], $assets['客厅']->id);
        $service->replace($page, [
            $this->assetNamed($item->project, ReusableAssetType::Character, '姐姐')->id,
            $this->assetNamed($item->project, ReusableAssetType::Character, '爸爸')->id,
            $this->assetNamed($item->project, ReusableAssetType::Character, '妈妈')->id,
        ], $this->assetNamed($item->project, ReusableAssetType::Scene, '客厅')->id);

        /** @var ContentPageReusableAssetReferenceReader $reader */
        $reader = $this->app->make(ContentPageReusableAssetReferenceReader::class);
        $names = $reader->names($page);

        $this->assertSame([
            ['type' => 'character', 'name' => '妈妈'],
            ['type' => 'character', 'name' => '姐姐'],
            ['type' => 'character', 'name' => '爸爸'],
            ['type' => 'scene', 'name' => '客厅'],
        ], $names, '人物按资产 id 升序稳定输出，场景排最后');
        // Reader 合同不扩张：只有 type / name 两个键。
        foreach ($names as $row) {
            $this->assertSame(['type', 'name'], array_keys($row));
        }
    }

    private function assetNamed(Project $project, ReusableAssetType $type, string $name): ReusableAsset
    {
        return ReusableAsset::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
            ->where('name', $name)
            ->firstOrFail();
    }

    // ── 6. 重复 character ids → 无重复 row ─────────────────────────────────

    public function test_duplicate_character_ids_create_no_duplicate_rows(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->saveDraft($item, $branch, [
            'units' => [[
                'id' => $unitId,
                'character_asset_ids' => [$assets['妈妈']->id, $assets['妈妈']->id, $assets['妈妈']->id],
                'scene_asset_id' => null,
            ]],
        ]);

        $this->assertSame(1, ContentPageReusableAssetReference::query()->where('content_page_id', $page->id)->count());
        $body = $this->show($item, $branch);
        $this->assertSame([$assets['妈妈']->id], $body['units'][0]['character_asset_ids']);
    }

    // ── 7~10. 跨 Project / 类型 / 未知 id → 422 ────────────────────────────

    public function test_cross_project_asset_is_rejected_with_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);

        $otherProject = Project::factory()->create();
        $otherItem = ContentItem::factory()->for($otherProject)->create();
        $foreign = ReusableAsset::query()->create([
            'project_id' => $otherProject->id,
            'type' => ReusableAssetType::Character->value,
            'name' => '外人',
            'status' => ReusableAssetStatus::Existing->value,
        ]);
        unset($otherItem);

        $unitId = $this->firstUnitId($item, $branch);
        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$foreign->id], 'scene_asset_id' => null]],
        ])->assertStatus(422);

        $this->assertSame(0, ContentPageReusableAssetReference::query()->count());
    }

    public function test_scene_id_pointing_at_character_is_rejected_with_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'character_asset_ids' => [], 'scene_asset_id' => $assets['妈妈']->id]],
        ])->assertStatus(422);
        $this->assertSame(0, ContentPageReusableAssetReference::query()->count());
    }

    public function test_character_ids_containing_scene_is_rejected_with_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id, $assets['客厅']->id], 'scene_asset_id' => null]],
        ])->assertStatus(422);
        $this->assertSame(0, ContentPageReusableAssetReference::query()->count());
    }

    public function test_unknown_asset_id_is_rejected_with_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $unitId = $this->firstUnitId($item, $branch);

        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'character_asset_ids' => [999999], 'scene_asset_id' => null]],
        ])->assertStatus(422);

        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'character_asset_ids' => [], 'scene_asset_id' => 999999]],
        ])->assertStatus(422);
    }

    public function test_same_asset_cannot_be_both_character_and_scene(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
        ]);

        /** @var ContentPageAssetReferenceService $service */
        $service = $this->app->make(ContentPageAssetReferenceService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->replace($page, [$assets['妈妈']->id], $assets['妈妈']->id);
    }

    // ── 11~13. [] 清人物 / null 清场景 / omitted 保持 ──────────────────────

    public function test_empty_array_clears_characters_and_null_clears_scene(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $body = $this->show($item, $branch);
        $this->assertSame(['妈妈'], $body['units'][0]['characters']);
        $this->assertSame('客厅', $body['units'][0]['scene']);

        // [] 清人物，null 清场景。
        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [], 'scene_asset_id' => null]],
        ]);
        $body = $this->show($item, $branch);
        $this->assertSame([], $body['units'][0]['characters']);
        $this->assertNull($body['units'][0]['scene']);
        $this->assertSame([], $body['units'][0]['character_asset_ids']);
        $this->assertNull($body['units'][0]['scene_asset_id']);
    }

    public function test_omitted_asset_fields_preserve_existing_references(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);

        // 旧式调用：只提交 special_requirement，不得清空资产引用。
        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'special_requirement' => '黄昏暖光']],
        ]);
        $body = $this->show($item, $branch);
        $this->assertSame(['妈妈'], $body['units'][0]['characters']);
        $this->assertSame('客厅', $body['units'][0]['scene']);
        $this->assertSame('黄昏暖光', $body['units'][0]['special_requirement']);
    }

    // ── 14. 页面删除 → refs cascade ────────────────────────────────────────

    public function test_deleting_a_page_cascades_its_references(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);

        /** @var ContentPageAssetReferenceService $service */
        $service = $this->app->make(ContentPageAssetReferenceService::class);
        $service->replace($page, [$assets['妈妈']->id], $assets['客厅']->id);
        $this->assertSame(2, ContentPageReusableAssetReference::query()->count());

        $page->delete();

        $this->assertSame(0, ContentPageReusableAssetReference::query()->count(), '页面删除后引用必须级联清理');
        $this->assertDatabaseHas('reusable_assets', ['id' => $assets['妈妈']->id], null, '页面删除不影响资产本身');
        $this->assertDatabaseHas('reusable_assets', ['id' => $assets['客厅']->id], null, '页面删除不影响资产本身');
    }

    // ── 15~17. Workspace GET：真实显式引用驱动 ─────────────────────────────

    public function test_workspace_characters_and_scene_come_from_explicit_references(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '公园', ReusableAssetStatus::Missing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        // 第二个 unit 指向 爸爸 / 公园（missing 资产同样可被引用）。
        $body = $this->show($item, $branch);
        $secondUnitId = (int) $body['units'][1]['id'];
        $this->saveDraft($item, $branch, [
            'units' => [[
                'id' => $secondUnitId,
                'character_asset_ids' => [$this->assetNamed($item->project, ReusableAssetType::Character, '爸爸')->id],
                'scene_asset_id' => $this->assetNamed($item->project, ReusableAssetType::Scene, '公园')->id,
            ]],
        ]);

        $body = $this->show($item, $branch);
        $this->assertSame(['妈妈'], $body['units'][0]['characters']);
        $this->assertSame('客厅', $body['units'][0]['scene']);
        $this->assertSame(['爸爸'], $body['units'][1]['characters']);
        $this->assertSame('公园', $body['units'][1]['scene']);
        $this->assertSame([$assets['妈妈']->id], $body['units'][0]['character_asset_ids']);
        $this->assertSame($assets['客厅']->id, $body['units'][0]['scene_asset_id']);
        $this->assertNull($body['units'][0]['special_requirement']);

        // 整批聚合：existing / missing 都必须真实出现。
        $this->assertSame([
            ['type' => 'character', 'name' => '妈妈', 'status' => 'existing'],
            ['type' => 'scene', 'name' => '客厅', 'status' => 'existing'],
            ['type' => 'character', 'name' => '爸爸', 'status' => 'missing'],
            ['type' => 'scene', 'name' => '公园', 'status' => 'missing'],
        ], $body['assets']);
    }

    public function test_same_asset_referenced_by_multiple_pages_is_deduped_in_summary(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->page($item, 3);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $body = $this->show($item, $branch);

        foreach ($body['units'] as $unit) {
            $this->saveDraft($item, $branch, [
                'units' => [[
                    'id' => (int) $unit['id'],
                    'character_asset_ids' => [$assets['妈妈']->id],
                    'scene_asset_id' => $assets['客厅']->id,
                ]],
            ]);
        }

        $body = $this->show($item, $branch);
        $this->assertCount(2, $body['assets'], '同一资产被多页引用，整批摘要只出现一次');
        $this->assertSame('character', $body['assets'][0]['type']);
        $this->assertSame('妈妈', $body['assets'][0]['name']);
    }

    // ── 18~20. last_used_at 冻结 / run snapshot ────────────────────────────

    public function test_last_used_at_is_never_touched_by_reference_flow(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $this->show($item, $branch);
        $this->postJson($this->base($item, $branch, '/copy-task'))->assertOk();
        $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])->assertCreated();

        $this->assertSame(
            ['妈妈' => null, '客厅' => null],
            ReusableAsset::query()->pluck('last_used_at', 'name')->all(),
            '显式选择 / GET / copy-task / queued run 都不得 touch last_used_at',
        );
    }

    public function test_queued_run_snapshot_contains_real_asset_summary(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);
        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);

        $result = $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])
            ->assertCreated()
            ->json();

        $run = ImageBatchRun::query()->findOrFail($result['batch_id']);
        $this->assertEquals([
            ['type' => 'character', 'name' => '妈妈', 'status' => 'existing'],
            ['type' => 'scene', 'name' => '客厅', 'status' => 'existing'],
        ], $run->instruction_snapshot['assets']);
        $this->assertSame('queued', $run->status->value, 'queued-only 语义不变');
    }

    // ── 21. copy task ─────────────────────────────────────────────────────

    public function test_copy_task_carries_page_characters_scene_and_missing_summary(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);
        $this->saveDraft($item, $branch, [
            'units' => [[
                'id' => $unitId,
                'character_asset_ids' => [$assets['妈妈']->id, $this->assetNamed($item->project, ReusableAssetType::Character, '爸爸')->id],
                'scene_asset_id' => $assets['客厅']->id,
            ]],
        ]);

        $task = $this->postJson($this->base($item, $branch, '/copy-task'))->assertOk()->json()['task'];

        $this->assertStringContainsString('  人物：妈妈、爸爸', $task);
        $this->assertStringContainsString('  场景：客厅', $task);
        $this->assertStringContainsString('爸爸（缺失）', $task);
    }

    // ── 22~23. Review 版本身份 ────────────────────────────────────────────

    public function test_review_version_changes_when_asset_identity_changes(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);
        $mother = $this->assetNamed($item->project, ReusableAssetType::Character, '妈妈');
        $father = $this->assetNamed($item->project, ReusableAssetType::Character, '爸爸');

        // 版本 A：P1 = 妈妈 / 客厅。
        $this->saveDraft($item, $branch, [
            'batch_instruction' => '暖调家庭日常',
            'units' => [['id' => $unitId, 'character_asset_ids' => [$mother->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/image_batch", [])
            ->assertCreated();
        $versionA = ReviewRequest::query()->latest('id')->firstOrFail()->version_label;

        // 资产 identity 改变：妈妈 → 爸爸。
        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$father->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/image_batch", [])
            ->assertCreated();
        $versionB = ReviewRequest::query()->latest('id')->firstOrFail()->version_label;

        $this->assertNotSame($versionA, $versionB, '人物身份变化必须形成新 version_label');
        $this->assertSame(2, ReviewRequest::query()->count());
    }

    public function test_review_version_is_stable_when_only_asset_status_changes(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);
        $father = $this->assetNamed($item->project, ReusableAssetType::Character, '爸爸');

        $this->saveDraft($item, $branch, [
            'batch_instruction' => '暖调家庭日常',
            'units' => [['id' => $unitId, 'character_asset_ids' => [$father->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/image_batch", [])
            ->assertCreated();
        $before = ReviewRequest::query()->latest('id')->firstOrFail()->version_label;

        // missing → existing：只是资产准备状态变化，引用 identity 未变。
        /** @var ReusableAssetIndexService $index */
        $index = $this->app->make(ReusableAssetIndexService::class);
        $index->update($item->project, $father, ['status' => ReusableAssetStatus::Existing]);

        // 同一版本重复提交 → 幂等返回既有 pending（200 + created=false），绝不是新版本。
        $resubmit = $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/image_batch", [])
            ->assertOk()
            ->json();
        $after = ReviewRequest::query()->latest('id')->firstOrFail()->version_label;

        $this->assertFalse($resubmit['data']['created']);
        $this->assertSame($before, $after, 'status-only 变化不得使 version_label 改变');
        $this->assertSame(1, ReviewRequest::query()->count(), '同一版本不得重复提交');
    }

    public function test_old_approval_does_not_cover_new_asset_identity(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);
        $mother = $this->assetNamed($item->project, ReusableAssetType::Character, '妈妈');
        $father = $this->assetNamed($item->project, ReusableAssetType::Character, '爸爸');

        $this->saveDraft($item, $branch, [
            'batch_instruction' => '暖调家庭日常',
            'units' => [['id' => $unitId, 'character_asset_ids' => [$mother->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $submitted = $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/image_batch", [])
            ->assertCreated()
            ->json();
        $review = ReviewRequest::query()->findOrFail($submitted['data']['review']['id']);
        $review->forceFill(['status' => ReviewStatus::Approved])->save();

        // 改成 爸爸 后重新提交：必须成功（旧 approval 不覆盖新版本）。
        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$father->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);
        $again = $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/image_batch", [])
            ->assertCreated()
            ->json();

        $this->assertTrue($again['data']['created']);
        $this->assertNotSame($review->version_label, ReviewRequest::query()->latest('id')->firstOrFail()->version_label);
    }

    // ── Payload Guard ─────────────────────────────────────────────────────

    public function test_forbidden_payload_fields_are_rejected_with_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);

        $forbidden = [
            ['characters' => ['妈妈']],
            ['scene' => '客厅'],
            ['asset_names' => ['妈妈']],
            ['asset_status' => ['existing']],
            ['project_id' => $item->project_id],
            ['page_id' => $page->id],
            ['reusable_asset' => ['id' => $assets['妈妈']->id]],
            ['last_used_at' => '2026-10-17 00:00:00'],
        ];

        foreach ($forbidden as $extra) {
            $this->putJson($this->base($item, $branch), [
                'units' => [array_merge(['id' => $unitId], $extra)],
            ])->assertStatus(422);
        }

        $this->assertSame(0, ContentPageReusableAssetReference::query()->count(), '被拒绝的字段不得产生任何写入');
    }

    public function test_workspace_unit_does_not_leak_whole_asset_objects(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $assets = $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);
        $unitId = $this->firstUnitId($item, $branch);
        $this->saveDraft($item, $branch, [
            'units' => [['id' => $unitId, 'character_asset_ids' => [$assets['妈妈']->id], 'scene_asset_id' => $assets['客厅']->id]],
        ]);

        $unit = $this->show($item, $branch)['units'][0];
        $this->assertSame([
            'id', 'page_id', 'page_no', 'page_type', 'final_copy', 'visual_evidence',
            'characters', 'scene', 'character_asset_ids', 'scene_asset_id',
            'special_requirement', 'status', 'source_changed', 'source_change_summary',
        ], array_keys($unit));
        $this->assertSame([$assets['妈妈']->id], $unit['character_asset_ids']);
        $this->assertSame($assets['客厅']->id, $unit['scene_asset_id']);
    }
}
