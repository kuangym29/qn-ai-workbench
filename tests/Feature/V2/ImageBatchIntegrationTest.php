<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ImageBatchRunStatus;
use App\Enums\ImageBatchUnitStatus;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I05 集成 —— 后端侧锁（真实 API + 真实 MySQL/SQLite，无 Mock）。
 *
 * 锁定的内容：
 *   1. GET 响应体就是 ImageBatchWorkspace 本体，不再包一层 data；
 *   2. units 字段集合固定，且全响应体任何位置都不出现 selected；
 *   3. PUT 白名单之外的系统字段一律 422；
 *   4. run（full / trial / partial）只产生 queued 记录，history 来自服务端；
 *   5. copy-task 返回 { task: string }，内容由服务端组装；
 *   6. 真实制造一次 ContentPage 来源变化 → keep / regenerate → 重新 GET 验证。
 */
class ImageBatchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** GET 体top-level key 顺序：前端直接消费响应体本身。 */
    private const WORKSPACE_KEYS = ['content_title', 'source_label', 'batch_instruction', 'batch_note', 'assets', 'units', 'history'];

    /** 单元字段集合（不含 selected）。 */
    private const UNIT_KEYS = [
        'id', 'page_id', 'page_no', 'page_type', 'final_copy', 'visual_evidence',
        'characters', 'scene', 'special_requirement', 'status', 'source_changed', 'source_change_summary',
    ];

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

    /** @param  array<string, mixed>  $payload */
    private function show(ContentItem $item, ContentBranch $branch): array
    {
        return $this->getJson($this->base($item, $branch))->assertOk()->json();
    }

    // ── 1. GET response shape ──────────────────────────────────────────────

    public function test_run_history_records_real_creation_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])->assertCreated();
        $this->assertSame(now()->toJSON(), $this->show($item, $branch)['history'][0]['created_at']);
    }

    public function test_get_returns_workspace_body_not_wrapped_in_data(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->acting($item);

        $body = $this->getJson($this->base($item, $branch))->assertOk()->json();

        $this->assertSame(self::WORKSPACE_KEYS, array_keys($body), 'GET 体必须是 ImageBatchWorkspace 本体');
        $this->assertArrayNotHasKey('data', $body, '不得出现 data.data 双层包裹');
    }

    public function test_unit_shape_is_fixed_and_carries_no_selected(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);

        $body = $this->show($item, $branch);

        $this->assertCount(1, $body['units']);
        $this->assertSame(self::UNIT_KEYS, array_keys($body['units'][0]));
        $this->assertArrayNotHasKey('selected', $body['units'][0]);
    }

    public function test_response_never_contains_selected_anywhere(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);

        $body = $this->show($item, $branch);

        $this->assertFalse($this->hasKeyDeep($body, 'selected'), '服务端任何层级都不得出现 selected');
    }

    // ── 3. PUT whitelist ───────────────────────────────────────────────────

    public function test_put_rejects_system_fields(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $unitId = $this->show($item, $branch)['units'][0]['id'];

        // 顶层系统字段
        $this->putJson($this->base($item, $branch), [
            'batch_instruction' => 'x',
            'status' => 'approved',
        ])->assertUnprocessable();

        // 单元系统字段
        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'final_copy' => 'hacked']],
        ])->assertUnprocessable();

        // 选择状态不允许写回
        $this->putJson($this->base($item, $branch), [
            'units' => [['id' => $unitId, 'selected' => true]],
        ])->assertUnprocessable();
    }

    public function test_put_accepts_only_draft_fields(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);
        $unitId = $this->show($item, $branch)['units'][0]['id'];

        $this->putJson($this->base($item, $branch), [
            'batch_instruction' => '柠檬黄暖调，儿童向插画',
            'batch_note' => '备注：封面留白',
            'units' => [['id' => $unitId, 'special_requirement' => 'P1 加一只纸箱']],
        ])->assertOk();

        $body = $this->show($item, $branch);
        $this->assertSame('柠檬黄暖调，儿童向插画', $body['batch_instruction']);
        $this->assertSame('备注：封面留白', $body['batch_note']);
        $this->assertSame('P1 加一只纸箱', $body['units'][0]['special_requirement']);
    }

    // ── 4. History from server ─────────────────────────────────────────────

    public function test_full_run_records_all_units_and_appears_in_history(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);
        $units = array_column($this->show($item, $branch)['units'], 'id');

        $result = $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])
            ->assertCreated()
            ->json();

        // full 忽略传入 unit_ids，由服务端解释全部页面。
        $this->assertSame($units, $result['unit_ids']);
        $this->assertSame('queued', $result['status'], '第一阶段真实状态只允许 queued');
        $this->assertSame(ImageBatchRunStatus::Queued->value, $result['status']);

        $history = $this->show($item, $branch)['history'];
        $this->assertCount(1, $history);
        $this->assertSame(['P1', 'P2'], $history[0]['page_labels']);
        $this->assertSame($result['batch_id'], $history[0]['id']);
        $this->assertSame('queued', $history[0]['status']);
    }

    public function test_trial_and_partial_use_selected_ids_from_client(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);
        $units = array_column($this->show($item, $branch)['units'], 'id');

        foreach (['trial', 'partial'] as $mode) {
            $result = $this->postJson($this->base($item, $branch, '/run'), [
                'mode' => $mode, 'unit_ids' => [$units[1]],
            ])->assertCreated()->json();

            $this->assertSame($mode, $result['mode']);
            $this->assertSame([$units[1]], $result['unit_ids']);
        }

        // 空选择直接被拒（前端也已 guard，这里是服务端真源）。
        $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'trial', 'unit_ids' => []])
            ->assertUnprocessable();

        $history = $this->show($item, $branch)['history'];
        $this->assertCount(2, $history);
        $this->assertSame(['P2'], $history[0]['page_labels'], 'history 倒序来自服务端');
    }

    public function test_history_summary_comes_from_server_snapshot(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->putJson($this->base($item, $branch), ['batch_instruction' => '服务端组装的说明'])->assertOk();
        $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])->assertCreated();

        $history = $this->show($item, $branch)['history'];
        $this->assertSame('服务端组装的说明', $history[0]['instruction_summary']);
    }

    // ── 5. Copy task ───────────────────────────────────────────────────────

    public function test_copy_task_returns_server_assembled_task(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1, ['final_copy' => '一只纸箱']);
        $this->page($item, 2);
        $this->acting($item);

        $this->putJson($this->base($item, $branch), [
            'batch_instruction' => '柠檬黄暖调',
            'batch_note' => '封面留白',
        ])->assertOk();

        $body = $this->postJson($this->base($item, $branch, '/copy-task'), [])->assertOk()->json();

        $this->assertSame(['task'], array_keys($body));
        $this->assertIsString($body['task']);
        $this->assertNotSame('', trim($body['task']));
        $this->assertStringContainsString('柠檬黄暖调', $body['task']);
        $this->assertStringContainsString('封面留白', $body['task']);
        $this->assertStringContainsString('一只纸箱', $body['task']);
    }

    // ── 6. Source change (real ContentPage modification) ───────────────────

    public function test_keep_and_regenerate_against_a_real_source_change(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $page = $this->page($item, 1, ['final_copy' => '原始文案']);
        $this->acting($item);

        $unitId = $this->show($item, $branch)['units'][0]['id'];
        $this->assertFalse($this->show($item, $branch)['units'][0]['source_changed'], '初次录入不算变化');

        // 真实制造一次来源变化：来源页内容被改动。
        $page->forceFill(['final_copy' => '来源页改后的文案'])->save();

        $changed = $this->show($item, $branch)['units'][0];
        $this->assertTrue($changed['source_changed'], '来源页变化必须由服务端标记');
        $this->assertNotNull($changed['source_change_summary']);

        // keep：保留当前生产状态。
        $this->postJson($this->base($item, $branch, '/units/'.$unitId.'/source-change'), ['action' => 'keep'])
            ->assertOk()
            ->assertJsonPath('status_value', ImageBatchUnitStatus::Pending->value);

        $kept = $this->show($item, $branch)['units'][0];
        $this->assertFalse($kept['source_changed'], 'keep 后必须重新 GET 才消除提示');
        $this->assertSame(ImageBatchUnitStatus::Pending->value, $kept['status'], 'keep 不改变生产状态');

        // 再次制造变化 → regenerate：服务端置 needs_revision。
        $page->forceFill(['final_copy' => '第二次改后的文案'])->save();
        $this->assertTrue($this->show($item, $branch)['units'][0]['source_changed']);

        $this->postJson($this->base($item, $branch, '/units/'.$unitId.'/source-change'), ['action' => 'regenerate'])
            ->assertOk()
            ->assertJsonPath('status_value', ImageBatchUnitStatus::NeedsRevision->value);

        $regenerated = $this->show($item, $branch)['units'][0];
        $this->assertFalse($regenerated['source_changed']);
        $this->assertSame(ImageBatchUnitStatus::NeedsRevision->value, $regenerated['status']);
    }

    // ── 7. Assets graceful empty state ─────────────────────────────────────

    public function test_assets_default_to_empty_list_without_index(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->acting($item);

        // I05 不吸收 B06：保持 Foundation + B05 的默认 AssetNameSource 行为。
        $this->assertFalse(class_exists(ReusableAsset::class), 'I05 不应引入 B06 资产索引');
        $body = $this->show($item, $branch);

        $this->assertSame([], $body['assets']);
        $this->assertSame([], $body['units'][0]['characters']);
        $this->assertNull($body['units'][0]['scene']);
    }

    // ── 8. Page route / permissions ────────────────────────────────────────

    public function test_image_batch_page_renders_official_component_with_three_ids(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->acting($item);

        $response = $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/image-batch")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('V2/ImageBatch/ImageBatchEditor')
                ->where('projectId', $item->project_id)
                ->where('itemId', $item->id)
                ->where('branchId', $branch->id));

        $this->assertNotNull($response);
    }

    public function test_image_batch_page_requires_login_and_project_scope(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $url = "/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/image-batch";

        $this->get($url)->assertRedirect('/auth/login');

        $this->actingAs(User::factory()->create());
        $this->get($url)->assertNotFound('未选择当前 Project → 404');
    }

    public function test_image_batch_page_rejects_non_eligible_branch(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::Article->value,
        ]);
        $this->acting($item);

        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/image-batch")
            ->assertStatus(422);
    }

    public function test_cross_project_page_and_api_return_404(): void
    {
        $mine = ContentItem::factory()->create();
        $theirs = ContentItem::factory()->create();
        $branch = $this->batchBranch($theirs);
        $this->acting($mine);

        $this->getJson($this->base($theirs, $branch))->assertNotFound();
        $this->get("/projects/{$theirs->project_id}/content/{$theirs->id}/branches/{$branch->id}/image-batch")
            ->assertNotFound();
    }

    public function test_api_requires_authentication(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);

        $this->getJson($this->base($item, $branch))->assertUnauthorized();
        $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full'])->assertUnauthorized();
    }

    /**
     * 递归检查任意层级是否出现某个 key。
     *
     * @param  array<string, mixed>|mixed  $value
     */
    private function hasKeyDeep(mixed $value, string $needle): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $child) {
            if ((string) $key === $needle) {
                return true;
            }
            if ($this->hasKeyDeep($child, $needle)) {
                return true;
            }
        }

        return false;
    }
}
