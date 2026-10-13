<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ImageBatchRunStatus;
use App\Enums\ImageBatchUnitStatus;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ImageBatchRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 图片批次生产正式 API（B05）。
 *
 * 合同真源：C05-R1（work/v2-p3-c05-r1-image-batch-contract）。
 *   GET    /api/projects/{p}/items/{i}/branches/{b}/image-batch
 *   PUT    /api/projects/{p}/items/{i}/branches/{b}/image-batch（白名单 draft）
 *   POST   .../image-batch/run（第一阶段只留执行记录，queued）
 *   POST   .../image-batch/copy-task（服务端组装任务文本）
 *   POST   .../image-batch/units/{unit}/source-change（keep / regenerate）
 *
 * 权限：未登录 401；跨 Project 404；非 image_post / 非 wechat_official 422。
 */
class ImageBatchApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    private function branch(ContentItem $item, string $channel = Channel::WechatOfficial->value, string $format = ContentFormat::ImagePost->value): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => $channel,
            'format' => $format,
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

    private function url(ContentItem $item, ContentBranch $branch, string $suffix = ''): string
    {
        return "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-batch".$suffix;
    }

    // ---------- GET ----------

    public function test_get_empty_draft_returns_defaults(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->acting($item);

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('content_title', $item->title)
            ->assertJsonPath('batch_instruction', '')
            ->assertJsonPath('batch_note', null)
            ->assertJsonPath('units', [])
            ->assertJsonPath('history', []);
    }

    public function test_get_existing_draft_returns_saved_values(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->acting($item);

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => '温暖家庭风',
            'batch_note' => 'P1 需要特写',
        ])->assertOk();

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('batch_instruction', '温暖家庭风')
            ->assertJsonPath('batch_note', 'P1 需要特写');
    }

    public function test_get_units_come_from_content_pages_in_page_order(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $p2 = $this->page($item, 2, ['final_copy' => '第二页']);
        $p1 = $this->page($item, 1, ['page_type' => 'cover', 'final_copy' => '封面页']);
        $this->acting($item);

        $response = $this->getJson($this->url($item, $branch))->assertOk();

        $response->assertJsonCount(2, 'units')
            ->assertJsonPath('units.0.page_no', 1)
            ->assertJsonPath('units.0.page_id', $p1->id)
            ->assertJsonPath('units.0.page_type', 'cover')
            ->assertJsonPath('units.0.final_copy', '封面页')
            ->assertJsonPath('units.0.status', ImageBatchUnitStatus::Pending->value)
            ->assertJsonPath('units.0.special_requirement', null)
            ->assertJsonPath('units.0.source_changed', false)
            ->assertJsonPath('units.1.page_no', 2)
            ->assertJsonPath('units.1.page_id', $p2->id)
            ->assertJsonPath('units.1.final_copy', '第二页');
    }

    public function test_get_assets_summary_is_lightweight_and_typed(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $assets = $this->getJson($this->url($item, $branch))->assertOk()->json('assets');

        $this->assertIsArray($assets);
        foreach ($assets as $asset) {
            $this->assertSame(['type', 'name', 'status'], array_keys($asset));
            $this->assertContains($asset['type'], ['character', 'scene']);
            $this->assertContains($asset['status'], ['existing', 'missing', 'new_in_batch']);
        }
    }

    public function test_get_never_returns_selected_field(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $body = $this->getJson($this->url($item, $branch))->assertOk()->json();

        $this->assertArrayNotHasKey('selected', $body);
        foreach ($body['units'] as $unit) {
            $this->assertArrayNotHasKey('selected', $unit);
        }
    }

    // ---------- PUT ----------

    public function test_put_saves_batch_instruction_and_note(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => '整批说明',
            'batch_note' => '整批备注',
        ])->assertOk()->assertJsonPath('status', 'saved');

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('batch_instruction', '整批说明')
            ->assertJsonPath('batch_note', '整批备注');
    }

    public function test_put_saves_unit_special_requirement(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);

        $unitId = $this->getJson($this->url($item, $branch))->json('units.1.id');

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => 'x',
            'units' => [['id' => $unitId, 'special_requirement' => '黄昏暖光']],
        ])->assertOk();

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('units.0.special_requirement', null)
            ->assertJsonPath('units.1.special_requirement', '黄昏暖光');
    }

    public function test_put_rejects_forbidden_fields(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => 'x',
            'final_copy' => 'hack',
        ])->assertStatus(422);

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => 'x',
            'status' => 'approved',
        ])->assertStatus(422);

        $unitId = $this->getJson($this->url($item, $branch))->json('units.0.id');
        $this->putJson($this->url($item, $branch), [
            'units' => [['id' => $unitId, 'special_requirement' => 'a', 'page_type' => 'cover']],
        ])->assertStatus(422);
    }

    public function test_put_rejects_unit_id_not_in_batch(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->putJson($this->url($item, $branch), [
            'units' => [['id' => 999999, 'special_requirement' => 'a']],
        ])->assertStatus(422);
    }

    // ---------- RUN ----------

    public function test_full_run_resolves_all_pages_and_ignores_unit_ids(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);

        $all = collect($this->getJson($this->url($item, $branch))->json('units'))->pluck('id')->all();

        $this->postJson($this->url($item, $branch, '/run'), [
            'mode' => 'full',
            'unit_ids' => [999999],
        ])->assertStatus(201)
            ->assertJsonPath('mode', 'full')
            ->assertJsonPath('status', ImageBatchRunStatus::Queued->value)
            ->assertJsonPath('unit_ids', $all);
    }

    public function test_trial_run_uses_selected_units(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);

        $unitId = $this->getJson($this->url($item, $branch))->json('units.0.id');

        $this->postJson($this->url($item, $branch, '/run'), [
            'mode' => 'trial',
            'unit_ids' => [$unitId],
        ])->assertStatus(201)
            ->assertJsonPath('mode', 'trial')
            ->assertJsonPath('unit_ids', [$unitId])
            ->assertJsonPath('status', 'queued');
    }

    public function test_partial_run_uses_selected_units(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->page($item, 3);
        $this->acting($item);

        $ids = collect($this->getJson($this->url($item, $branch))->json('units'))->pluck('id');

        $this->postJson($this->url($item, $branch, '/run'), [
            'mode' => 'partial',
            'unit_ids' => [$ids[0], $ids[2]],
        ])->assertStatus(201)
            ->assertJsonPath('mode', 'partial')
            ->assertJsonPath('unit_ids', [$ids[0], $ids[2]]);
    }

    public function test_trial_run_with_empty_units_is_rejected(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'trial', 'unit_ids' => []])
            ->assertStatus(422);
    }

    public function test_partial_run_with_empty_units_is_rejected(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'partial'])
            ->assertStatus(422);
    }

    public function test_run_rejects_foreign_unit_ids_and_bad_mode(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'trial', 'unit_ids' => [424242]])
            ->assertStatus(422);

        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'everything'])
            ->assertStatus(422);
    }

    public function test_run_snapshot_keeps_instruction_pages_and_assets(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1, ['final_copy' => '封面文字']);
        $this->acting($item);

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => '快照说明',
            'batch_note' => '快照备注',
        ])->assertOk();

        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])
            ->assertStatus(201);

        $run = ImageBatchRun::query()->firstOrFail();
        $snapshot = $run->instruction_snapshot;

        $this->assertSame('full', $snapshot['mode']);
        $this->assertSame('快照说明', $snapshot['batch_instruction']);
        $this->assertSame('快照备注', $snapshot['batch_note']);
        $this->assertCount(1, $snapshot['pages']);
        $this->assertSame('封面文字', $snapshot['pages'][0]['final_copy']);
        $this->assertArrayHasKey('special_requirement', $snapshot['pages'][0]);
        $this->assertArrayHasKey('assets', $snapshot);
        $this->assertArrayHasKey('summary', $snapshot);
    }

    public function test_history_lists_runs_newest_first(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);

        $ids = collect($this->getJson($this->url($item, $branch))->json('units'))->pluck('id');

        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'trial', 'unit_ids' => [$ids[0]]])
            ->assertStatus(201);
        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])
            ->assertStatus(201);

        $history = $this->getJson($this->url($item, $branch))->assertOk()->json('history');

        $this->assertCount(2, $history);

        // full 跑全部页面（P1、P2），trial 只跑 P1；倒序返回。
        $this->assertSame('queued', $history[0]['status']);
        $this->assertSame(['P1', 'P2'], $history[0]['page_labels']);
        $this->assertSame('queued', $history[1]['status']);
        $this->assertSame(['P1'], $history[1]['page_labels']);
        $this->assertArrayHasKey('created_at', $history[0]);
        $this->assertArrayHasKey('instruction_summary', $history[0]);
    }

    // ---------- COPY TASK ----------

    public function test_copy_task_returns_server_assembled_text(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1, ['final_copy' => '封面文字']);
        $this->acting($item);

        $response = $this->postJson($this->url($item, $branch, '/copy-task'), [])->assertOk();

        $task = $response->json('task');
        $this->assertIsString($task);
        $this->assertStringContainsString('【整批生图说明】', $task);
        $this->assertStringContainsString('【页面单元】', $task);
        $this->assertStringContainsString('封面文字', $task);
        $this->assertArrayHasKey('task', $response->json());
    }

    public function test_copy_task_includes_latest_draft_without_client_prompt(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->putJson($this->url($item, $branch), [
            'batch_instruction' => '最新整批说明',
            'batch_note' => '最新整批备注',
        ])->assertOk();

        $task = $this->postJson($this->url($item, $branch, '/copy-task'), [])->assertOk()->json('task');

        $this->assertStringContainsString('最新整批说明', $task);
        $this->assertStringContainsString('最新整批备注', $task);
    }

    // ---------- SOURCE CHANGE ----------

    public function test_source_change_detected_after_page_edit(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $page = $this->page($item, 1);
        $this->acting($item);

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('units.0.source_changed', false)
            ->assertJsonPath('units.0.source_change_summary', null);

        $page->update(['final_copy' => '改过的最终文字']);

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('units.0.source_changed', true)
            ->assertJsonPath('units.0.source_change_summary', '来源页面内容有更新，建议检查本页生图是否受影响。');
    }

    public function test_keep_clears_source_changed_and_keeps_status(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $page = $this->page($item, 1);
        $this->acting($item);

        $unitId = $this->getJson($this->url($item, $branch))->json('units.0.id');
        $page->update(['final_copy' => '改过了']);

        $this->postJson($this->url($item, $branch, "/units/{$unitId}/source-change"), ['action' => 'keep'])
            ->assertOk()
            ->assertJsonPath('status_value', ImageBatchUnitStatus::Pending->value);

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('units.0.source_changed', false)
            ->assertJsonPath('units.0.status', ImageBatchUnitStatus::Pending->value);
    }

    public function test_regenerate_clears_source_changed_and_flags_needs_revision(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $page = $this->page($item, 1);
        $this->acting($item);

        $unitId = $this->getJson($this->url($item, $branch))->json('units.0.id');
        $page->update(['visual_evidence' => '改过的画面证据']);

        $this->postJson($this->url($item, $branch, "/units/{$unitId}/source-change"), ['action' => 'regenerate'])
            ->assertOk()
            ->assertJsonPath('status_value', ImageBatchUnitStatus::NeedsRevision->value);

        $this->getJson($this->url($item, $branch))
            ->assertOk()
            ->assertJsonPath('units.0.source_changed', false)
            ->assertJsonPath('units.0.status', ImageBatchUnitStatus::NeedsRevision->value);
    }

    public function test_source_change_rejects_unknown_unit_and_action(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->acting($item);

        $this->postJson($this->url($item, $branch, '/units/999999/source-change'), ['action' => 'keep'])
            ->assertStatus(404);

        $unitId = $this->getJson($this->url($item, $branch))->json('units.0.id');
        $this->postJson($this->url($item, $branch, "/units/{$unitId}/source-change"), ['action' => 'delete'])
            ->assertStatus(422);
    }

    // ---------- PERMISSIONS ----------

    public function test_cross_project_returns_404(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);

        $other = Project::factory()->create();
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $other->id]);

        $this->getJson("/api/projects/{$other->id}/items/{$item->id}/branches/{$branch->id}/image-batch")
            ->assertNotFound();
    }

    public function test_wrong_format_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item, Channel::WechatOfficial->value, ContentFormat::Article->value);
        $this->page($item, 1);
        $this->acting($item);

        $this->getJson($this->url($item, $branch))->assertStatus(422);
    }

    public function test_wrong_channel_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item, Channel::Xiaohongshu->value, ContentFormat::ImagePost->value);
        $this->page($item, 1);
        $this->acting($item);

        $this->getJson($this->url($item, $branch))->assertStatus(422);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $this->page($item, 1);

        $this->getJson($this->url($item, $branch))->assertStatus(401);
        $this->postJson($this->url($item, $branch, '/run'), ['mode' => 'full'])->assertStatus(401);
        $this->postJson($this->url($item, $branch, '/copy-task'), [])->assertStatus(401);
    }

    public function test_branch_not_belonging_to_item_returns_404(): void
    {
        $item = ContentItem::factory()->create();
        $otherItem = ContentItem::factory()->create();
        $foreignBranch = $this->branch($otherItem);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$foreignBranch->id}/image-batch")
            ->assertNotFound();
    }
}
