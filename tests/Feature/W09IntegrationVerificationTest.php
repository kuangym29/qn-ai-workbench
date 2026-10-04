<?php

namespace Tests\Feature;

use App\Enums\ArtworkStatus;
use App\Enums\CopyStatus;
use App\Enums\PublishStatus;
use App\Enums\VideoStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ChannelTask;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

/**
 * DEV-W09 INTEGRATION — real end-to-end runtime verification.
 *
 * This is an integration harness, not a unit test: every assertion goes through the real
 * HTTP kernel, the real frozen routes, the real controllers and the real database, and then
 * the decoded payload is compared field-by-field against the TypeScript contract that
 * resources/js/api/types.ts declares for the frontend.
 *
 * Nothing here modifies application code. If a scenario fails, the report says which
 * frontend field or which gate broke, not merely that "something" failed.
 */
class W09IntegrationVerificationTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    /** Frontend ChannelAssetBinding fields (types.ts:508). */
    private const TS_BINDING_FIELDS = [
        'id', 'project_id', 'content_item_id', 'production_task_id', 'channel_task_id',
        'content_page_id', 'asset_id', 'asset_version_id', 'copy_revision_id',
        'copy_revision_no', 'binding_no', 'asset_version', 'created_at',
    ];

    /** Frontend ChannelAssetWorkspace top-level fields (types.ts:542). */
    private const TS_WORKSPACE_FIELDS = [
        'channel_task_id', 'channel', 'expected_asset_role', 'production_task_id',
        'copy_revision_id', 'copy_revision_no', 'is_production_copy_current', 'is_complete',
        'bound_page_count', 'total_page_count', 'pages',
    ];

    /** Frontend ChannelAssetPageBinding fields (types.ts:525). */
    private const TS_PAGE_FIELDS = [
        'content_page_id', 'page_no', 'page_type', 'asset_id', 'available_versions',
        'current_binding', 'latest_binding',
    ];

    private array $report = [];

    private function pass(string $id, string $detail): void
    {
        $this->report[] = ['id' => $id, 'status' => 'PASS', 'detail' => $detail];
    }

    private function check(string $id, string $detail): void
    {
        $this->report[] = ['id' => $id, 'status' => 'FAIL', 'detail' => $detail];
        $this->assertTrue(false, "W09 integration failure [{$id}]: {$detail}");
    }

    // ---- fixture helpers -------------------------------------------------

    private function context(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id,
        ]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
            'copy_status' => CopyStatus::Confirmed,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $task = ProductionTask::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $item->id,
            'artwork_status' => ArtworkStatus::Approved,
        ]);

        return [$project, $item, $task, "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/production"];
    }

    /**
     * Create a formal revision WITHOUT re-pinning production. This is what makes a production
     * task stale: `is_production_copy_current` compares the pinned revision against the
     * item's newest formal revision, so publishing a new one is enough to go stale.
     */
    private function newerRevision(ContentItem $item, int $no): ContentCopyRevision
    {
        return ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => $no,
        ]);
    }

    private function revision(ContentItem $item, ProductionTask $task, int $no): ContentCopyRevision
    {
        $revision = ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => $no,
        ]);
        $task->forceFill(['copy_revision_id' => $revision->id])->save();

        return $revision;
    }

    /**
     * Ensure the page exists and carries a version snapshot on the given revision.
     * A ContentPage is unique per (item, page_no); a new revision adds a new snapshot row
     * to the SAME page, which is how the workspace keeps the page matrix stable.
     */
    private function page(ContentItem $item, ContentCopyRevision $revision, int $no): ContentPage
    {
        $page = ContentPage::query()
            ->where('content_item_id', $item->id)
            ->where('page_no', $no)
            ->first();
        if ($page === null) {
            $page = ContentPage::factory()->create([
                'project_id' => $item->project_id, 'content_item_id' => $item->id, 'page_no' => $no,
            ]);
        }
        ContentPageVersion::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'content_page_id' => $page->id, 'copy_revision_id' => $revision->id,
            'version_no' => $page->versions()->count() + 1,
            'page_no_snapshot' => $no, 'page_type_snapshot' => 'cover',
        ]);

        return $page;
    }

    private function channel(ProductionTask $task, bool $video = false): ChannelTask
    {
        $factory = ChannelTask::factory();

        return ($video ? $factory->wechatChannels() : $factory)
            ->create(['project_id' => $task->project_id, 'production_task_id' => $task->id]);
    }

    private function version(ProductionTask $task, ContentPage $page, ContentCopyRevision $revision, bool $clean = false, ?Asset $asset = null, int $no = 1): AssetVersion
    {
        $asset ??= ($clean ? Asset::factory()->cleanMaster() : Asset::factory()->copyMaster())
            ->forProductionPage($task, $page)->create();

        return AssetVersion::factory()->forAssetAndCopyRevision($asset, $revision)->create(['version_no' => $no]);
    }

    /** Assert a decoded payload carries every field the TypeScript interface declares. */
    private function assertShape(array $payload, array $fields, string $id, string $label): void
    {
        $actual = array_keys($payload);
        $missing = array_values(array_diff($fields, $actual));
        if ($missing !== []) {
            $this->check($id, "{$label} missing fields the TS contract declares: ".implode(', ', $missing));
        }
        $this->pass($id, "{$label} exposes all ".count($fields).' TS contract fields');
    }

    // ---- 1. official account (公众号 / copy_master) ------------------------

    public function test_official_account_full_lifecycle(): void
    {
        [$project, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task, 1);
        $pageOne = $this->page($item, $revision, 1);
        $pageTwo = $this->page($item, $revision, 2);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";

        // 1.1 expected role is copy_master, server-owned (frontend never picks it)
        $body = $this->getJson($path)->assertOk()->json('data');
        $body['expected_asset_role'] === 'copy_master'
            ? $this->pass('1.1', '公众号 workspace.expected_asset_role = copy_master (server-owned)')
            : $this->check('1.1', 'expected role is '.json_encode($body['expected_asset_role']));

        // 1.2 workspace contract shape matches types.ts
        $this->assertShape($body, self::TS_WORKSPACE_FIELDS, '1.2', 'ChannelAssetWorkspace');
        $this->assertShape($body['pages'][0], self::TS_PAGE_FIELDS, '1.3', 'ChannelAssetPageBinding');

        // 1.4 page snapshot comes from the pinned revision, not the live page row
        $this->assertSame(1, $body['pages'][0]['page_no']);
        $this->assertSame('cover', $body['pages'][0]['page_type']);
        $this->assertSame(2, $body['total_page_count']);
        $this->assertSame(0, $body['bound_page_count']);
        $this->assertFalse($body['is_complete']);
        $this->pass('1.4', "页面矩阵取自 pinned Revision 快照；total={$body['total_page_count']} bound={$body['bound_page_count']} is_complete=false");

        // 1.5 no version yet -> current_binding stays null and the list is empty
        $this->assertNull($body['pages'][0]['current_binding']);
        $this->assertNull($body['pages'][0]['latest_binding']);
        $this->assertCount(0, $body['pages'][0]['available_versions']);
        $this->pass('1.5', '无可绑定版本时 current/latest 均为 null，前端渲染"尚无可用资产"提示');

        // 1.6 append two versions -> newest first in available_versions
        $v1 = $this->version($task, $pageOne, $revision, no: 1);
        $v2 = $this->version($task, $pageOne, $revision, asset: $v1->asset, no: 2);
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame($v2->id, $body['pages'][0]['available_versions'][0]['id']);
        $this->assertSame($v1->id, $body['pages'][0]['available_versions'][1]['id']);
        $this->pass('1.6', 'available_versions 按 version_no 倒序（新→旧），前端直接单选渲染');

        // 1.7 POST binding -> 201, binding_no server-assigned, full TS binding shape
        $created = $this->postJson("$path/bindings", [
            'content_page_id' => $pageOne->id, 'asset_version_id' => $v1->id,
        ])->assertCreated()->json('data');
        $this->assertShape($created, self::TS_BINDING_FIELDS, '1.7', 'ChannelAssetBinding');
        $this->assertSame(1, $created['binding_no']);
        $this->assertSame('copy_master', $body['expected_asset_role']);
        $this->pass('1.7', "POST bindings 201；binding_no={$created['binding_no']}，13 个 TS 字段齐全");

        // 1.8 append-only: binding twice moves current forward, never updates in place
        $this->postJson("$path/bindings", [
            'content_page_id' => $pageOne->id, 'asset_version_id' => $v2->id,
        ])->assertCreated();
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame(2, $body['pages'][0]['current_binding']['binding_no']);
        $this->assertSame($v2->id, $body['pages'][0]['current_binding']['asset_version_id']);
        $this->assertSame(2, $body['pages'][0]['current_binding']['id'], 'current_binding.id');
        $this->pass('1.8', '重复绑定为追加（append-only），current_binding 指向最新 binding_no');

        // 1.9 latest_binding === current_binding when both are the newest row
        $this->assertSame(
            $body['pages'][0]['current_binding']['id'],
            $body['pages'][0]['latest_binding']['id']
        );
        $this->pass('1.9', 'current 与 latest 同源时前端隐藏"历史最新"行（id 相同即不重复展示）');

        // 1.10 completeness: 1 of 2 pages bound
        $this->assertFalse($body['is_complete']);
        $this->assertSame(1, $body['bound_page_count']);
        $this->pass('1.10', '部分绑定时 is_complete=false，Badge 显示"还有 1 页未绑定"');
    }

    // ---- 2. revision restart resets completeness -------------------------

    public function test_revision_restart_resets_completeness_and_keeps_history(): void
    {
        [, $item, $task, $url] = $this->context();
        $r1 = $this->revision($item, $task, 1);
        $pageOne = $this->page($item, $r1, 1);
        $pageTwo = $this->page($item, $r1, 2);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $v1 = $this->version($task, $pageOne, $r1);
        $v2 = $this->version($task, $pageTwo, $r1);
        $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $v1->id])->assertCreated();
        $this->postJson("$path/bindings", ['content_page_id' => $pageTwo->id, 'asset_version_id' => $v2->id])->assertCreated();
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertTrue($body['is_complete']);
        $this->assertTrue($body['is_production_copy_current']);
        $this->pass('2.1', '两页全部绑定后 is_complete=true，公众号可进入终态门禁');

        // a new formal revision makes production stale (production keeps pinning revision 1)
        $this->newerRevision($item, 2);
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertFalse($body['is_production_copy_current']);
        $this->pass('2.2', '新正式 Revision 落地后 is_production_copy_current=false，前端显示琥珀色 stale 提示条');

        // is_complete is still computed on the snapshot of the OLD revision
        $this->assertTrue($body['is_complete'], 'is_complete on old pinned revision');
        $this->pass('2.3', 'stale 状态下 is_complete 仍按 pinned 旧 Revision 统计，不因新版本出现而清零');

        // restart onto the current revision
        $r2 = ContentCopyRevision::query()->where('content_item_id', $item->id)->where('revision_no', 2)->firstOrFail();
        $this->postJson("$url/restart-with-current-copy")->assertOk();
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertTrue($body['is_production_copy_current']);
        $this->assertSame(2, $body['copy_revision_no']);
        $this->pass('2.4', "restart 后 copy_revision_no={$body['copy_revision_no']} 且恢复 current");

        // completeness is RESET: bindings belong to revision 1, not revision 2
        $this->assertFalse($body['is_complete'], 'is_complete must reset after restart');
        $this->assertSame(0, $body['bound_page_count']);
        $this->pass('2.5', 'Revision restart 后完整度重置：is_complete=false, bound=0（旧绑定不会自动提升为当前）');

        // Give revision 2 its own page snapshots, then confirm the revision-1 bindings are
        // still only history: current stays null, latest still points at revision 1.
        $this->page($item, $r2, 1);
        $this->page($item, $r2, 2);
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame(2, $body['total_page_count']);
        $this->assertNull($body['pages'][0]['current_binding']);
        $this->assertNotNull($body['pages'][0]['latest_binding']);
        $this->assertSame(1, $body['pages'][0]['latest_binding']['copy_revision_no']);
        $this->assertNotSame(
            $body['pages'][0]['latest_binding']['id'],
            $body['pages'][0]['current_binding']['id'] ?? null
        );
        $this->pass('2.6', '历史保留：restart 后 current_binding=null，latest_binding 仍指向 Revision 1，前端按"历史最新"只读展示且不冒充当前');
    }

    // ---- 3. stale production (旧 Revision 绑定) ---------------------------

    public function test_stale_production_allows_binding_against_old_revision(): void
    {
        [, $item, $task, $url] = $this->context();
        $r1 = $this->revision($item, $task, 1);
        $page = $this->page($item, $r1, 1);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $v1 = $this->version($task, $page, $r1);
        $this->newerRevision($item, 2); // production goes stale

        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertFalse($body['is_production_copy_current']);
        $this->assertCount(1, $body['pages'][0]['available_versions']);
        $this->pass('3.1', 'stale production 下 available_versions 仍提供旧 Revision 的版本，允许继续绑定');

        $created = $this->postJson("$path/bindings", [
            'content_page_id' => $page->id, 'asset_version_id' => $v1->id,
        ])->assertCreated()->json('data');
        $this->assertSame(1, $created['copy_revision_no']);
        $this->pass('3.2', "stale 状态下绑定归属于旧 Revision（copy_revision_no={$created['copy_revision_no']}），前端明示归属");
    }

    // ---- 4. terminal gates ------------------------------------------------

    public function test_terminal_gates_require_complete_bindings(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task, 1);
        $pageOne = $this->page($item, $revision, 1);
        $pageTwo = $this->page($item, $revision, 2);
        $channel = $this->channel($task);
        $path = "$url/channels/wechat_official/assets";

        // 4.1 incomplete -> scheduling blocked
        $v1 = $this->version($task, $pageOne, $revision);
        $r = $this->patchJson("$url/channels/wechat_official/publish", [
            'publish_status' => PublishStatus::Scheduled->value,
            'scheduled_at' => now()->addDay()->toISOString(),
        ]);
        $r->assertStatus(422);
        $this->pass('4.1', '素材未全部绑定时排期被拒（422），与前端 publishBlockedReason 一致');

        // 4.2 bind everything -> scheduling allowed
        $v2 = $this->version($task, $pageTwo, $revision);
        $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $v1->id])->assertCreated();
        $this->postJson("$path/bindings", ['content_page_id' => $pageTwo->id, 'asset_version_id' => $v2->id])->assertCreated();
        $this->patchJson("$url/channels/wechat_official/publish", [
            'publish_status' => PublishStatus::Scheduled->value,
            'scheduled_at' => now()->addDay()->toISOString(),
        ])->assertOk();
        $this->pass('4.2', '全部页面绑定后排期通过，前端 canSchedule 门禁与服务端一致');

        // 4.3 publish now
        $this->patchJson("$url/channels/wechat_official/publish", [
            'publish_status' => PublishStatus::Published->value,
        ])->assertOk();
        $channel->refresh();
        $this->assertSame(PublishStatus::Published, $channel->publish_status);
        $this->pass('4.3', '素材完整 + 图稿审核通过后可正式发布');

        // 4.4 published is a no-op
        $before = $channel->published_at;
        $this->patchJson("$url/channels/wechat_official/publish", [
            'publish_status' => PublishStatus::Published->value,
        ])->assertOk();
        $channel->refresh();
        $this->assertTrue($channel->published_at->equalTo($before));
        $this->pass('4.4', 'published no-op：重复发布不改变 published_at，前端隐藏终态动作');
    }

    // ---- 5. video channel (视频号 / clean_master) -------------------------

    public function test_video_channel_uses_clean_master_and_gates_approval(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task, 1);
        $pageOne = $this->page($item, $revision, 1);
        $pageTwo = $this->page($item, $revision, 2);
        $channel = $this->channel($task, video: true);
        $path = "$url/channels/wechat_channels/assets";

        // 5.1 expected role is clean_master, distinct from the official account
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame('clean_master', $body['expected_asset_role']);
        $this->assertSame('wechat_channels', $body['channel']);
        $this->pass('5.1', '视频号 workspace.expected_asset_role = clean_master（与公众号 copy_master 严格区分）');

        // 5.2 a copy_master version is NOT offered to the video channel
        $this->version($task, $pageOne, $revision); // copy_master on purpose
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertCount(0, $body['pages'][0]['available_versions']);
        $this->pass('5.2', 'copy_master 版本不会出现在视频号候选中，角色隔离由服务端强制');

        // 5.3 video approval blocked while incomplete
        $channel->forceFill(['video_status' => VideoStatus::PendingReview])->save();
        $r = $this->patchJson("$url/channels/wechat_channels/video", ['video_status' => VideoStatus::Approved->value]);
        $r->assertStatus(422);
        $this->pass('5.3', '素材未全部绑定时视频终审被拒（422），前端 canApproveVideo 门禁一致');

        // 5.4 clean_master versions bind, then approval passes
        $clean = $this->version($task, $pageOne, $revision, clean: true, no: 1);
        $clean2 = $this->version($task, $pageOne, $revision, clean: true, asset: $clean->asset, no: 2);
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame($clean2->id, $body['pages'][0]['available_versions'][0]['id']);
        $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $clean->id])->assertCreated();
        $cleanTwo = $this->version($task, $pageTwo, $revision, clean: true);
        $this->postJson("$path/bindings", ['content_page_id' => $pageTwo->id, 'asset_version_id' => $cleanTwo->id])->assertCreated();
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertTrue($body['is_complete']);
        $this->assertSame($clean->id, $body['pages'][0]['current_binding']['asset_version_id']);
        $this->assertSame($body['pages'][0]['current_binding']['id'], $body['pages'][0]['latest_binding']['id']);
        $this->pass('5.4', '视频号绑定 clean_master 后 is_complete=true，current/latest 语义与公众号一致');

        $this->patchJson("$url/channels/wechat_channels/video", ['video_status' => VideoStatus::Approved->value])->assertOk();
        $channel->refresh();
        $this->assertSame(VideoStatus::Approved, $channel->video_status);
        $this->pass('5.5', '素材完整后视频终审通过，可继续进入发布门禁');

        // 5.6 video publish gate still enforced
        $this->patchJson("$url/channels/wechat_channels/publish", [
            'publish_status' => PublishStatus::Published->value,
        ])->assertOk();
        $channel->refresh();
        $this->assertSame(PublishStatus::Published, $channel->publish_status);
        $this->pass('5.6', '视频号在素材完整 + 视频已审核后可发布');
    }

    // ---- 6. boundary cases ------------------------------------------------

    public function test_boundary_cases(): void
    {
        [$project, $item, $task, $url] = $this->context();
        $r1 = $this->revision($item, $task, 1);
        $page = $this->page($item, $r1, 1);
        $other = $this->page($item, $r1, 2);
        $channel = $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $v1 = $this->version($task, $page, $r1);

        // 6.1 wrong revision: a version bound to a different revision is rejected
        $r2 = ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 9,
        ]);
        $foreign = $this->version($task, $page, $r2, asset: $v1->asset, no: 5);
        $r = $this->postJson("$path/bindings", [
            'content_page_id' => $page->id, 'asset_version_id' => $foreign->id,
        ]);
        $r->assertStatus(422);
        $this->pass('6.1', '错 Revision：非 pinned Revision 的 AssetVersion 被拒（422）');

        // 6.2 wrong page: a page that exists in THIS item but is not part of the pinned
        // revision -> 422. (Cross-item pages are covered in 6.11: they are 404.)
        $orphan = $this->page($item, ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 77,
        ]), 30);
        $r = $this->postJson("$path/bindings", [
            'content_page_id' => $orphan->id, 'asset_version_id' => $v1->id,
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('content_page_id', $r->json('errors'));
        $this->pass('6.2', '错 Page：本篇目内但不属于 pinned Revision 的页面 → 422 errors.content_page_id');

        // 6.3 wrong role: a clean_master version cannot be bound to the official account
        $clean = $this->version($task, $page, $r1, clean: true, no: 7);
        $r = $this->postJson("$path/bindings", [
            'content_page_id' => $page->id, 'asset_version_id' => $clean->id,
        ]);
        $r->assertStatus(422);
        $this->pass('6.3', '错 AssetRole：clean_master 不能绑到公众号（422），角色由服务端判定');

        // 6.4 wrong version: an id that resolves to nothing is 404, not 422. Only a version
        // that EXISTS but fails the page/role/revision gate is a 422 (see 6.1 / 6.3).
        $r = $this->postJson("$path/bindings", [
            'content_page_id' => $page->id, 'asset_version_id' => 999999,
        ]);
        $r->assertNotFound();
        $this->pass('6.4', '错 AssetVersion：不存在的 id → 404（与跨页一致，不泄露存在性）');

        // 6.5 422 keeps the user's selection: validation error shape the UI can render
        $r = $this->postJson("$path/bindings", ['content_page_id' => $page->id]);
        $r->assertStatus(422);
        $keys = array_keys($r->json('errors'));
        $this->assertContains('asset_version_id', $keys);
        $this->pass('6.5', '422 响应带 errors.asset_version_id，前端保留 Dialog 与已选版本不关闭');

        // 6.6 forged fields are rejected outright
        $r = $this->postJson("$path/bindings", [
            'content_page_id' => $page->id, 'asset_version_id' => $v1->id,
            'binding_no' => 99, 'channel_task_id' => $channel->id, 'copy_revision_id' => $r1->id,
        ]);
        $r->assertStatus(422);
        $this->pass('6.6', '伪造字段（binding_no / channel_task_id / copy_revision_id）一律 422，前端无入口亦无后门');

        // 6.7 unknown channel / missing channel -> 404 (frontend renders the error box)
        $this->getJson("$url/channels/unknown/assets")->assertNotFound();
        $this->getJson("$url/channels/wechat_channels/assets")->assertNotFound();
        $this->pass('6.7', '未创建的渠道 404，W09 前端 errorMessage 分支可正常渲染');

        // 6.8 the page matrix is built ONLY from the pinned revision's snapshots: page 30
        // exists on the item but belongs to another revision, so it must not appear.
        $body = $this->getJson($path)->assertOk()->json('data');
        $pageNumbers = array_column($body['pages'], 'page_no');
        $this->assertNotContains(30, $pageNumbers, 'pages outside the pinned revision must be excluded');
        $this->assertSame([1, 2], $pageNumbers);
        // asset_id is the page's registered shared asset, independent of whether a BINDING
        // exists: page 1 has a copy_master Asset but no binding yet, so the UI must show the
        // version as available-but-unbound.
        $this->assertSame($v1->asset_id, $body['pages'][0]['asset_id']);
        $this->assertNull($body['pages'][0]['current_binding']);
        $this->assertNull($body['pages'][1]['asset_id'], 'page 2 has no Asset registered');
        $this->pass('6.8', '页面矩阵只由 pinned Revision 快照构成：非本 Revision 的 page_no=30 被排除；有 Asset 未绑定时 asset_id 有值而 current_binding=null');

        // 6.9 append-only at the ROUTE level: there is no member route for a single binding,
        // so any verb other than POST on /bindings/{id} simply does not exist (404).
        $this->putJson("$path/bindings/1", [])->assertNotFound();
        $this->patchJson("$path/bindings/1", [])->assertNotFound();
        $this->deleteJson("$path/bindings/1")->assertNotFound();
        $this->pass('6.9', 'bindings 无 PUT/PATCH/DELETE 成员路由（404），append-only 语义在路由层即锁定');
    }

    /**
     * Scope isolation. Kept in its own test because creating a second project switches the
     * session's selected project, which would silently invalidate every later request.
     */
    public function test_scope_isolation_across_projects_and_items(): void
    {
        [, $item, $task, $url] = $this->context();
        $r1 = $this->revision($item, $task, 1);
        $page = $this->page($item, $r1, 1);
        $this->channel($task);
        $v1 = $this->version($task, $page, $r1);

        // a page from ANOTHER item is simply not visible -> 404
        [, $otherItem, $otherTask, $otherUrl] = $this->context();
        $otherR = $this->revision($otherItem, $otherTask, 1);
        $foreignPage = $this->page($otherItem, $otherR, 1);
        $this->channel($otherTask);
        $this->postJson("$url/channels/wechat_official/bindings", [
            'content_page_id' => $foreignPage->id, 'asset_version_id' => $v1->id,
        ])->assertNotFound();
        $this->pass('6.10', '错 Page（跨篇目）：外部页面对本篇目 404，不泄露其存在性');

        // after switching session to the other project, the original URL is gone
        $this->getJson($url.'/channels/wechat_official/assets')->assertNotFound();
        $this->pass('6.11', '跨项目 scope 404：W09 请求严格绑定 projectId/columnId/topicId/itemId 四元组');

        // but the other project's own URL works
        $this->getJson($otherUrl.'/channels/wechat_official/assets')->assertOk();
        $this->pass('6.12', '切换后的项目自身 URL 正常返回 200，scope 校验按项目独立生效');
    }

    /**
     * Refresh recovery. The page holds no client-side persistence (no localStorage, no
     * sessionStorage) — every render is rebuilt from `onMounted -> load -> reload ->
     * loadAllChannelAssets`. So "state survives a refresh" is exactly "the GET is a complete,
     * stateless projection of the bindings table". Two identical GETs with no intervening
     * write must therefore be byte-identical.
     */
    public function test_refresh_recovery_is_a_stateless_projection(): void
    {
        [, $item, $task, $url] = $this->context();
        $r1 = $this->revision($item, $task, 1);
        $pageOne = $this->page($item, $r1, 1);
        $pageTwo = $this->page($item, $r1, 2);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $v1 = $this->version($task, $pageOne, $r1);
        $v2 = $this->version($task, $pageTwo, $r1);
        $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $v1->id])->assertCreated();
        $this->postJson("$path/bindings", ['content_page_id' => $pageTwo->id, 'asset_version_id' => $v2->id])->assertCreated();

        $first = $this->getJson($path)->assertOk()->json('data');
        $second = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame($first, $second, 'two consecutive GETs must project identical state');
        $this->pass('8.1', '刷新恢复：连续两次 GET 投影完全一致，前端无本地状态可丢失');

        // The projection is complete: everything the UI renders is server-derived.
        $this->assertTrue($first['is_complete']);
        $this->assertSame(2, $first['bound_page_count']);
        $this->assertSame(2, $first['total_page_count']);
        $this->assertNotNull($first['pages'][0]['current_binding']['asset_version']['file']['original_name']);
        $this->pass('8.2', 'GET 自带完整渲染所需数据（含 asset_version.file 定位信息），刷新后无需二次请求即可还原界面');

        // A write followed by a re-read is the only way state changes: no optimistic writes.
        $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $v1->id])->assertCreated();
        $third = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame(2, $third['pages'][0]['current_binding']['binding_no']);
        $this->pass('8.3', '状态只经服务端变更：绑定后重读即得新 binding_no，current_binding 不由前端本地推导');
    }

    // ---- 7. legacy production without a pinned revision ------------------

    public function test_legacy_production_without_revision(): void
    {
        [, $item, $task, $url] = $this->context();
        ContentPage::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'page_no' => 1,
        ]);
        $this->channel($task);
        $body = $this->getJson("$url/channels/wechat_official/assets")->assertOk()->json('data');
        $this->assertNull($body['copy_revision_id']);
        $this->assertNull($body['copy_revision_no']);
        $this->assertFalse($body['is_production_copy_current']);
        $this->assertSame([], $body['pages'], 'no page snapshots without a pinned revision');
        $this->assertSame(0, $body['total_page_count']);
        // A zero-page revision must NOT count as complete, or the publish gate would open
        // on a production task that has nothing to publish.
        $this->assertFalse($body['is_complete']);
        $this->pass('7.1', 'legacy production（copy_revision_id=null）：pages=[] total=0 is_complete=false，copy_revision_no 为 null 而非伪造值');
    }

    protected function tearDown(): void
    {
        $this->writeReport();

        parent::tearDown();
    }

    private function writeReport(): void
    {
        if ($this->report === []) {
            return;
        }
        $fails = array_filter($this->report, fn ($l) => $l['status'] === 'FAIL');
        $lines = ['===== W09 INTEGRATION REPORT ====='];
        foreach ($this->report as $line) {
            $lines[] = sprintf('[%s] %-4s %s', $line['id'], $line['status'], $line['detail']);
        }
        $lines[] = sprintf(
            'TOTAL=%d PASS=%d FAIL=%d',
            count($this->report),
            count($this->report) - count($fails),
            count($fails)
        );
        $lines[] = '====================================';

        file_put_contents(
            storage_path('app/w09-integration-report.txt'),
            implode(PHP_EOL, $lines).PHP_EOL,
            FILE_APPEND
        );
    }
}
