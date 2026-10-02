<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\CopyStatus;
use App\Models\ChannelTask;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\Topic;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DEV-W05 — ChannelTask 渠道流程 API。
 *
 * 覆盖：创建门禁、默认状态、重复渠道、视频规则、发布排期与实际发布时间、
 * published 幂等与不可回退、stale Production 只读、显式整链 restart 及其门禁。
 */
class ChannelTaskApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Project, 1: ContentColumn, 2: Topic, 3: ContentItem, 4: string} */
    private function context(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        return [$project, $column, $topic, $item,
            "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/production"];
    }

    private function revision(ContentItem $item, int $number = 1): ContentCopyRevision
    {
        return ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => $number,
        ]);
    }

    /**
     * 建立一个「可直接建渠道」的环境：Revision 1 + confirmed + ProductionTask + artwork approved。
     * 返回与 context() 相同的 5 元组。
     *
     * @return array{0: Project, 1: ContentColumn, 2: Topic, 3: ContentItem, 4: string}
     */
    private function readyProduction(int $revisionNumber = 1): array
    {
        [$project, $column, $topic, $item, $url] = $this->context();
        $this->revision($item, $revisionNumber);
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $this->postJson($url)->assertCreated();
        $this->patchJson($url, ['artwork_status' => 'approved'])->assertOk();

        return [$project, $column, $topic, $item, $url];
    }

    private function channelUrl(string $url, string $channel, string $suffix = ''): string
    {
        return $url."/channels/{$channel}{$suffix}";
    }

    // ======================================================= 迁移兼容

    public function test_schedule_columns_exist_and_default_to_null_for_existing_rows(): void
    {
        $this->assertTrue(Schema::hasColumns('channel_tasks', ['scheduled_at', 'published_at']));

        [, , , $item, $url] = $this->context();
        $revision = $this->revision($item);
        $task = ProductionTask::factory()->forCopyRevision($revision)->create();
        $legacy = ChannelTask::factory()->create([
            'project_id' => $item->project_id, 'production_task_id' => $task->id,
        ]);

        // 既有行迁移后两个时间列必须为 null。
        $this->assertNull($legacy->fresh()->scheduled_at);
        $this->assertNull($legacy->fresh()->published_at);
        $this->assertNull($legacy->fresh()->getRawOriginal('scheduled_at'));
        $this->assertNull($legacy->fresh()->getRawOriginal('published_at'));

        // 列定义允许为空（仅在 SQLite 内存库上用 pragma 断言；MySQL 由迁移验收覆盖）。
        if (DB::connection()->getDriverName() === 'sqlite') {
            $notNull = DB::selectOne(
                'SELECT COUNT(*) AS c FROM pragma_table_info(\'channel_tasks\')
                 WHERE name IN (\'scheduled_at\',\'published_at\') AND "notnull" = 1'
            );

            $this->assertSame(0, (int) $notNull->c);
        }
    }

    // ======================================================= 读取

    public function test_list_requires_production_task_and_returns_only_created_channels(): void
    {
        [, , , $item, $url] = $this->context();

        // 没有 ProductionTask → 404
        $this->getJson($url.'/channels')->assertNotFound();

        $this->revision($item);
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $this->postJson($url)->assertCreated();

        // ProductionTask 存在但没有渠道 → {"data":[]}
        $this->getJson($url.'/channels')->assertOk()->assertExactJson(['data' => []]);

        $this->patchJson($url, ['artwork_status' => 'approved'])->assertOk();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $this->assertCount(1, $this->getJson($url.'/channels')->assertOk()->json('data'));
    }

    public function test_list_keeps_fixed_channel_order_official_first(): void
    {
        [, , , , $url] = $this->readyProduction();

        // 故意先建视频号，列表仍须公众号在前。
        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        $channels = $this->getJson($url.'/channels')->assertOk()->json('data');
        $this->assertSame(['wechat_official', 'wechat_channels'], array_column($channels, 'channel'));
    }

    public function test_show_returns_single_channel_and_404s_for_unknown_or_uncreated(): void
    {
        [, , , , $url] = $this->readyProduction();

        $this->getJson($this->channelUrl($url, 'wechat_official'))->assertNotFound();

        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $this->getJson($this->channelUrl($url, 'wechat_official'))->assertOk()
            ->assertJsonPath('data.channel', 'wechat_official');

        // 未知 Channel → 404，不是 422
        $this->getJson($this->channelUrl($url, 'douyin'))->assertNotFound();

        // 合法但尚未创建 → 404
        $this->getJson($this->channelUrl($url, 'wechat_channels'))->assertNotFound();
    }

    public function test_resource_exposes_production_binding_and_current_flag(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $revision = ContentCopyRevision::where('content_item_id', $item->id)->firstOrFail();

        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated()
            ->assertJsonPath('data.production_copy_revision_id', $revision->id)
            ->assertJsonPath('data.production_copy_revision_no', 1)
            ->assertJsonPath('data.artwork_status', 'approved')
            ->assertJsonPath('data.is_production_copy_current', true)
            ->assertJsonPath('data.scheduled_at', null)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonStructure([
                'data' => ['id', 'project_id', 'production_task_id', 'channel', 'video_status', 'publish_status',
                    'scheduled_at', 'published_at', 'production_copy_revision_id', 'production_copy_revision_no',
                    'artwork_status', 'is_production_copy_current', 'created_at', 'updated_at'],
            ]);
    }

    // ======================================================= 创建门禁

    public function test_create_requires_production_task_artwork_approval_and_current_revision(): void
    {
        [, , , $item, $url] = $this->context();
        $channelsUrl = $url.'/channels';

        // 1) 没有 ProductionTask → 422（Production 不存在由 restart 场景另测 404）
        $this->revision($item);
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $this->postJson($channelsUrl, ['channel' => 'wechat_official'])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->postJson($url)->assertCreated();

        // 2) artwork 各中间态 → 422
        foreach (['not_started', 'in_progress', 'pending_review'] as $status) {
            $this->patchJson($url, ['artwork_status' => $status])->assertOk();
            $this->postJson($channelsUrl, ['channel' => 'wechat_official'])
                ->assertUnprocessable()->assertJsonValidationErrors('channel');
        }

        $this->patchJson($url, ['artwork_status' => 'approved'])->assertOk();
        $this->postJson($channelsUrl, ['channel' => 'wechat_official'])->assertCreated();

        // 3) Production stale → 422
        $this->revision($item, 2);
        $this->postJson($channelsUrl, ['channel' => 'wechat_channels'])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');
    }

    public function test_create_applies_per_channel_defaults_and_allows_both_channels(): void
    {
        [, , , , $url] = $this->readyProduction();

        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated()
            ->assertJsonPath('data.video_status', 'not_applicable')
            ->assertJsonPath('data.publish_status', 'unpublished');

        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated()
            ->assertJsonPath('data.video_status', 'not_started')
            ->assertJsonPath('data.publish_status', 'unpublished');

        $this->assertDatabaseCount('channel_tasks', 2);
    }

    public function test_create_never_auto_creates_the_other_channel(): void
    {
        [, , , , $url] = $this->readyProduction();

        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $this->assertDatabaseCount('channel_tasks', 1);
        $this->assertDatabaseMissing('channel_tasks', ['channel' => 'wechat_channels']);
    }

    public function test_duplicate_channel_is_rejected(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertDatabaseCount('channel_tasks', 1);
    }

    public function test_create_rejects_forged_and_unknown_fields(): void
    {
        [, , , , $url] = $this->readyProduction();

        foreach (['project_id', 'production_task_id', 'video_status', 'publish_status', 'scheduled_at', 'published_at'] as $field) {
            $this->postJson($url.'/channels', ['channel' => 'wechat_official', $field => 1])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($url.'/channels', ['channel' => 'douyin'])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->postJson($url.'/channels', [])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertDatabaseCount('channel_tasks', 0);
    }

    // ======================================================= 视频

    public function test_official_account_video_status_is_permanently_not_applicable(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        foreach (['not_started', 'in_progress', 'pending_review', 'approved', 'not_applicable'] as $status) {
            $this->patchJson($this->channelUrl($url, 'wechat_official', '/video'), ['video_status' => $status])
                ->assertUnprocessable()->assertJsonValidationErrors('video_status');
        }
        $this->assertDatabaseHas('channel_tasks', ['channel' => 'wechat_official', 'video_status' => 'not_applicable']);
    }

    public function test_channels_video_progresses_and_approval_requires_current_production(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated();

        foreach (['in_progress', 'pending_review', 'not_started'] as $status) {
            $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => $status])
                ->assertOk()->assertJsonPath('data.video_status', $status);
        }

        // not_applicable 不允许
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'not_applicable'])
            ->assertUnprocessable()->assertJsonValidationErrors('video_status');

        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'approved'])
            ->assertOk()->assertJsonPath('data.video_status', 'approved');

        // Production stale → approved 422，但中间态仍可保留
        $this->revision($item, 2);
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'in_progress'])
            ->assertOk();
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('video_status');
        $this->assertDatabaseHas('channel_tasks', ['channel' => 'wechat_channels', 'video_status' => 'in_progress']);
    }

    public function test_video_update_never_touches_publish_dimension(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated();
        $video = $this->channelUrl($url, 'wechat_channels', '/video');

        $this->patchJson($video, ['video_status' => 'approved'])->assertOk()
            ->assertJsonPath('data.publish_status', 'unpublished')
            ->assertJsonPath('data.scheduled_at', null)
            ->assertJsonPath('data.published_at', null);

        $this->assertDatabaseHas('channel_tasks', [
            'channel' => 'wechat_channels', 'video_status' => 'approved',
            'publish_status' => 'unpublished', 'scheduled_at' => null, 'published_at' => null,
        ]);
    }

    public function test_video_request_rejects_publish_fields(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated();
        $video = $this->channelUrl($url, 'wechat_channels', '/video');

        foreach (['publish_status', 'scheduled_at', 'published_at', 'project_id', 'production_task_id', 'channel'] as $field) {
            $this->patchJson($video, ['video_status' => 'in_progress', $field => 1])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    // ======================================================= 发布

    public function test_official_account_schedules_publishes_and_clears_schedule(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $publish = $this->channelUrl($url, 'wechat_official', '/publish');

        // scheduled 必须带 scheduled_at
        $this->patchJson($publish, ['publish_status' => 'scheduled'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        // 排期时间正确转换并按 UTC 保存
        $scheduled = $this->patchJson($publish, [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00',
        ])->assertOk()->assertJsonPath('data.publish_status', 'scheduled')->json('data');

        $this->assertSame('2026-10-10T02:00:00.000000Z', $scheduled['scheduled_at']);
        $this->assertNull($scheduled['published_at']);

        // 重新排期可改 scheduled_at
        $again = $this->patchJson($publish, [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-12T09:30:00+08:00',
        ])->assertOk()->json('data');
        $this->assertSame('2026-10-12T01:30:00.000000Z', $again['scheduled_at']);

        // scheduled → unpublished 清空两个时间
        $cleared = $this->patchJson($publish, ['publish_status' => 'unpublished'])->assertOk()->json('data');
        $this->assertSame('unpublished', $cleared['publish_status']);
        $this->assertNull($cleared['scheduled_at']);
        $this->assertNull($cleared['published_at']);
        $this->assertDatabaseHas('channel_tasks', [
            'channel' => 'wechat_official', 'publish_status' => 'unpublished', 'scheduled_at' => null, 'published_at' => null,
        ]);
    }

    public function test_publish_defaults_published_at_to_now_and_keeps_schedule_history(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $publish = $this->channelUrl($url, 'wechat_official', '/publish');

        $this->patchJson($publish, [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00',
        ])->assertOk();

        // 未提供 published_at → now()；原 scheduled_at 保留为排期历史
        $published = $this->patchJson($publish, ['publish_status' => 'published'])->assertOk()->json('data');
        $this->assertSame('published', $published['publish_status']);
        $this->assertNotNull($published['published_at']);
        $this->assertSame('2026-10-10T02:00:00.000000Z', $published['scheduled_at']);
        $this->assertTrue(now()->subMinute()->lt(Carbon::parse($published['published_at'])));
    }

    public function test_publish_accepts_client_supplied_published_at(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        $result = $this->patchJson($this->channelUrl($url, 'wechat_official', '/publish'), [
            'publish_status' => 'published', 'published_at' => '2026-10-11T08:00:00+08:00',
        ])->assertOk()->json('data');
        $this->assertSame('2026-10-11T00:00:00.000000Z', $result['published_at']);
    }

    public function test_published_is_idempotent_and_cannot_be_erased(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $publish = $this->channelUrl($url, 'wechat_official', '/publish');

        $first = $this->patchJson($publish, [
            'publish_status' => 'published', 'published_at' => '2026-10-11T08:00:00+08:00',
        ])->assertOk()->json('data');
        $original = $first['published_at'];

        // 幂等：再次 published 且不带 published_at → 保留原值，不重新 now()
        $repeat = $this->patchJson($publish, ['publish_status' => 'published'])->assertOk()->json('data');
        $this->assertSame($original, $repeat['published_at']);

        // 已发布试图改 published_at → 422
        $this->patchJson($publish, [
            'publish_status' => 'published', 'published_at' => '2026-11-01T08:00:00+08:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('published_at');

        // published → unpublished → 422（不可抹掉已发布事实）
        $this->patchJson($publish, ['publish_status' => 'unpublished'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');

        $this->assertDatabaseHas('channel_tasks', [
            'channel' => 'wechat_official', 'publish_status' => 'published',
        ]);
        $this->assertSame($original, $this->getJson($this->channelUrl($url, 'wechat_official'))->json('data.published_at'));
    }

    public function test_unpublished_request_refuses_forged_timestamps(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $publish = $this->channelUrl($url, 'wechat_official', '/publish');

        $this->patchJson($publish, ['publish_status' => 'unpublished', 'scheduled_at' => '2026-10-10T10:00:00+08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->patchJson($publish, ['publish_status' => 'unpublished', 'published_at' => '2026-10-10T10:00:00+08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('published_at');
    }

    public function test_scheduled_rejects_published_at_and_requires_schedule_time(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $publish = $this->channelUrl($url, 'wechat_official', '/publish');

        $this->patchJson($publish, ['publish_status' => 'scheduled', 'published_at' => '2026-10-10T10:00:00+08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('published_at');
    }

    public function test_channels_publish_requires_approved_video(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated();
        $publish = $this->channelUrl($url, 'wechat_channels', '/publish');

        // 视频未验收 → 两种终态都 422
        $this->patchJson($publish, ['publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        $this->patchJson($publish, ['publish_status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');

        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'approved'])->assertOk();

        $this->patchJson($publish, ['publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00'])
            ->assertOk()->assertJsonPath('data.publish_status', 'scheduled');
        $this->patchJson($publish, ['publish_status' => 'published'])
            ->assertOk()->assertJsonPath('data.publish_status', 'published');
    }

    public function test_publish_requires_current_production(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $this->revision($item, 2);
        $publish = $this->channelUrl($url, 'wechat_official', '/publish');

        $this->patchJson($publish, ['publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        $this->patchJson($publish, ['publish_status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');
    }

    // ======================================================= stale 语义

    public function test_new_formal_revision_never_auto_mutates_production_or_channels(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $official = $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated()->json('data');
        $channels = $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated()->json('data');
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'approved'])->assertOk();
        $this->patchJson($this->channelUrl($url, 'wechat_official', '/publish'), [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00',
        ])->assertOk();

        $productionId = $official['production_task_id'];

        // 确认 Revision 2 —— 必须不自动改动任何生产/渠道状态
        $this->revision($item, 2);

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.artwork_status', 'approved')
            ->assertJsonPath('data.is_copy_revision_current', false);
        $this->assertDatabaseHas('production_tasks', ['id' => $productionId, 'artwork_status' => 'approved']);

        foreach ([['wechat_official', $official], ['wechat_channels', $channels]] as [$channel, $created]) {
            $fresh = $this->getJson($this->channelUrl($url, $channel))->assertOk();
            $this->assertFalse($fresh->json('data.is_production_copy_current'));
            $this->assertSame($created['id'], $fresh->json('data.id'));
        }
        $this->assertDatabaseHas('channel_tasks', [
            'id' => $official['id'], 'channel' => 'wechat_official', 'video_status' => 'not_applicable', 'publish_status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('channel_tasks', [
            'id' => $channels['id'], 'channel' => 'wechat_channels', 'video_status' => 'approved', 'publish_status' => 'unpublished',
        ]);
    }

    public function test_stale_production_blocks_final_channel_states_only(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated();
        $video = $this->channelUrl($url, 'wechat_channels', '/video');
        $publish = $this->channelUrl($url, 'wechat_channels', '/publish');

        $this->revision($item, 2);

        // 中间态在 stale 上允许保留
        $this->patchJson($video, ['video_status' => 'in_progress'])->assertOk();
        $this->patchJson($video, ['video_status' => 'pending_review'])->assertOk();

        // 终态被阻止
        $this->patchJson($video, ['video_status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('video_status');
        $this->patchJson($publish, ['publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        $this->patchJson($publish, ['publish_status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('publish_status');
    }

    public function test_draft_only_edit_keeps_production_current(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        // 只保存新草稿（copy_status → editing），未确认 Revision 2
        $base = substr($url, 0, -strlen('/production'));
        $page = $this->postJson($base.'/pages', ['page_no' => 1, 'page_type' => 'content'])->assertCreated()->json('data.id');
        $this->postJson($base."/pages/{$page}/drafts", ['page_title' => '新草稿'])->assertCreated();

        $this->assertSame(CopyStatus::Editing, $item->fresh()->copy_status);

        // 当前最大正式 Revision 仍是 Revision 1 → 依然 current
        $this->getJson($url)->assertOk()->assertJsonPath('data.is_copy_revision_current', true);
        $this->getJson($this->channelUrl($url, 'wechat_official'))->assertOk()
            ->assertJsonPath('data.is_production_copy_current', true);
    }

    // ======================================================= 整链 restart

    public function test_restart_resets_production_and_all_channels_in_place(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $official = $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated()->json('data');
        $channels = $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated()->json('data');
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'approved'])->assertOk();
        $this->patchJson($this->channelUrl($url, 'wechat_official', '/publish'), [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00',
        ])->assertOk();

        $second = $this->revision($item, 2);

        $result = $this->postJson($url.'/restart-with-current-copy')->assertOk();
        $this->assertSame($second->id, $result->json('data.production.copy_revision_id'));
        $this->assertSame('not_started', $result->json('data.production.artwork_status'));

        // ChannelTask ID 与 channel 保持不变，只重置状态与时间
        $this->assertDatabaseHas('channel_tasks', [
            'id' => $official['id'], 'channel' => 'wechat_official', 'video_status' => 'not_applicable',
            'publish_status' => 'unpublished', 'scheduled_at' => null, 'published_at' => null,
        ]);
        $this->assertDatabaseHas('channel_tasks', [
            'id' => $channels['id'], 'channel' => 'wechat_channels', 'video_status' => 'not_started',
            'publish_status' => 'unpublished', 'scheduled_at' => null, 'published_at' => null,
        ]);
        $this->assertDatabaseCount('channel_tasks', 2);
        $this->assertDatabaseHas('production_tasks', [
            'copy_revision_id' => $second->id, 'artwork_status' => 'not_started',
        ]);
    }

    public function test_restart_is_noop_when_production_already_current(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $this->patchJson($this->channelUrl($url, 'wechat_official', '/publish'), [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00',
        ])->assertOk();

        $before = ChannelTask::firstOrFail();
        $beforeUpdated = $before->updated_at;

        $this->postJson($url.'/restart-with-current-copy')->assertOk();

        $after = ChannelTask::firstOrFail();
        $this->assertSame($before->publish_status, $after->publish_status);
        $this->assertSame($before->scheduled_at?->toISOString(), $after->scheduled_at?->toISOString());
        $this->assertEquals($beforeUpdated, $after->updated_at);
        $this->assertDatabaseHas('production_tasks', ['artwork_status' => 'approved']);
    }

    public function test_restart_rejected_when_any_channel_is_published(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $official = $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated()->json('data');
        $channels = $this->postJson($url.'/channels', ['channel' => 'wechat_channels'])->assertCreated()->json('data');
        $this->patchJson($this->channelUrl($url, 'wechat_official', '/publish'), [
            'publish_status' => 'published', 'published_at' => '2026-10-11T08:00:00+08:00',
        ])->assertOk();
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'approved'])->assertOk();
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/publish'), [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-20T10:00:00+08:00',
        ])->assertOk();

        $second = $this->revision($item, 2);

        $this->postJson($url.'/restart-with-current-copy')
            ->assertUnprocessable()->assertJsonValidationErrors('production');

        // ProductionTask 与所有 ChannelTask 原样保留
        $this->assertDatabaseHas('production_tasks', [
            'copy_revision_id' => $official['production_copy_revision_id'], 'artwork_status' => 'approved',
        ]);
        $this->assertDatabaseHas('channel_tasks', [
            'id' => $official['id'], 'publish_status' => 'published', 'scheduled_at' => null,
        ]);
        $this->assertDatabaseHas('channel_tasks', [
            'id' => $channels['id'], 'publish_status' => 'scheduled',
        ]);
        $this->assertNotSame($second->id, $official['production_copy_revision_id']);
    }

    public function test_restart_requires_existing_channel_tasks(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->assertDatabaseCount('channel_tasks', 0);

        $this->postJson($url.'/restart-with-current-copy')
            ->assertUnprocessable()->assertJsonValidationErrors('production');
    }

    public function test_restart_requires_confirmed_copy_and_rejects_payload(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $this->revision($item, 2);
        $item->update(['copy_status' => CopyStatus::Editing]);

        $this->postJson($url.'/restart-with-current-copy')
            ->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->postJson($url.'/restart-with-current-copy', ['copy_revision_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('copy_revision_id');
    }

    public function test_restart_404s_without_production_task(): void
    {
        [, , , , $url] = $this->context();
        $this->postJson($url.'/restart-with-current-copy')->assertNotFound();
    }

    public function test_restart_does_not_touch_copy_history(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();
        $first = ContentCopyRevision::where('content_item_id', $item->id)->firstOrFail();
        $second = $this->revision($item, 2);

        $this->postJson($url.'/restart-with-current-copy')->assertOk();

        // Revision 不可变：数量、编号、确认时间都不变
        $this->assertDatabaseCount('content_copy_revisions', 2);
        $this->assertDatabaseHas('content_copy_revisions', [
            'id' => $first->id, 'revision_no' => 1, 'confirmed_at' => $first->confirmed_at?->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseHas('content_copy_revisions', [
            'id' => $second->id, 'revision_no' => 2,
        ]);
    }

    // ======================================================= 作用域

    public function test_scope_rejects_wrong_ancestors_and_foreign_session(): void
    {
        [, , , $item, $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        $other = Project::factory()->create();
        $otherColumn = ContentColumn::factory()->for($other)->create();
        $otherTopic = Topic::factory()->create(['project_id' => $other->id, 'content_column_id' => $otherColumn->id]);
        $wrong = "/api/projects/{$other->id}/columns/{$otherColumn->id}/topics/{$otherTopic->id}/items/{$item->id}/production";

        $this->getJson($wrong.'/channels')->assertNotFound();
        $this->postJson($wrong.'/channels', ['channel' => 'wechat_official'])->assertNotFound();
        $this->getJson($wrong.'/channels/wechat_official')->assertNotFound();
        $this->patchJson($wrong.'/channels/wechat_official/video', ['video_status' => 'in_progress'])->assertNotFound();
        $this->patchJson($wrong.'/channels/wechat_official/publish', ['publish_status' => 'published'])->assertNotFound();
        $this->postJson($wrong.'/restart-with-current-copy')->assertNotFound();

        // 同 Project 但错误篇目 → 404
        $otherItem = ContentItem::factory()->create([
            'project_id' => $other->id, 'content_column_id' => $otherColumn->id, 'topic_id' => $otherTopic->id,
        ]);
        $siblingItemUrl = "/api/projects/{$other->id}/columns/{$otherColumn->id}/topics/{$otherTopic->id}/items/{$otherItem->id}/production";
        $this->getJson($siblingItemUrl.'/channels')->assertNotFound();
    }

    public function test_video_and_publish_404_for_uncreated_channel(): void
    {
        [, , , , $url] = $this->readyProduction();
        $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated();

        // 合法渠道但未创建 → 404
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/video'), ['video_status' => 'in_progress'])->assertNotFound();
        $this->patchJson($this->channelUrl($url, 'wechat_channels', '/publish'), ['publish_status' => 'published'])->assertNotFound();
    }

    public function test_no_delete_endpoint_exists_for_channel_tasks(): void
    {
        [, , , , $url] = $this->readyProduction();
        $created = $this->postJson($url.'/channels', ['channel' => 'wechat_official'])->assertCreated()->json('data');

        // 路由表里不存在任何 DELETE 写入口：{channel} 只声明了 GET 与两个 PATCH 子路径，
        // 因此 DELETE / POST 到单渠道都不会创建、修改或删除任何记录（404 或 405 均可）。
        $deleteStatus = $this->json('DELETE', $this->channelUrl($url, 'wechat_official'))->status();
        $postStatus = $this->postJson($this->channelUrl($url, 'wechat_official'))->status();
        $this->assertContains($deleteStatus, [404, 405]);
        $this->assertContains($postStatus, [404, 405]);
        $this->assertDatabaseHas('channel_tasks', ['id' => $created['id']]);
    }
}
