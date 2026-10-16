<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2 视频号视频制作正式 API（B04）。
 *
 * GET  /api/projects/{project}/items/{item}/branches/{branch}/video
 * PUT  同上
 * POST 同上 /source-change   （body: {action: "keep"}）
 *
 * 覆盖任务 §十三 的验证项：GET 空/已保存、来源页自动形成 units、PUT 新建/修改/unchanged、
 * 版本新增与 unchanged 不新增版本、duration 校验与负数拒绝、不存在 source_page 拒绝、
 * total_duration / timeline、source fingerprint / source_changed / keep / keep 后不 changed、
 * 跨 Project 404、非 video branch 422、非视频号 branch 422、未登录 401。
 */
class VideoApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    private function videoBranch(ContentItem $item, string $channel = 'wechat_channels', string $format = 'video'): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create(['channel' => $channel, 'format' => $format]);
    }

    private function pages(ContentItem $item, int $count = 2): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ContentPage::factory()->for($item)->create([
                'page_no' => $i,
                'page_type' => $i === 1 ? 'cover' : 'content',
                'final_copy' => "copy {$i}",
            ]);
        }
    }

    private function unitPayload(int $pageId, string $instruction, float $duration, ?string $note = null): array
    {
        return [
            'source_page_id' => $pageId,
            'dynamic_instruction' => $instruction,
            'duration_seconds' => $duration,
            'note' => $note,
        ];
    }

    // ---------- GET ----------

    public function test_get_empty_content_returns_defaults(): void
    {
        $item = ContentItem::factory()->create(['title' => '视频主题']);
        $branch = $this->videoBranch($item);
        $this->pages($item, 2);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertOk()
            ->assertJsonPath('video.id', null)
            ->assertJsonPath('video.title', '视频主题')
            ->assertJsonPath('video.source_label', '视频制作')
            ->assertJsonPath('video.batch_instruction', '')
            ->assertJsonPath('video.narration', '')
            ->assertJsonPath('video.source_changed', false)
            ->assertJsonPath('video.source_change_summary', null)
            ->assertJsonCount(2, 'video.units')
            // 尚未正式保存 → source_changed 恒 false
            ->assertJsonPath('video.units.0.dynamic_instruction', '')
            ->assertJsonPath('video.units.0.note', null);

        // 默认时长为合理默认值 3.0 秒（JSON 数字表示可能是 3 或 3.0，按数值比较）。
        $units = $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->json('video.units');
        $this->assertEqualsWithDelta(3.0, $units[0]['duration_seconds'], 0.001);
        $this->assertEqualsWithDelta(3.0, $units[1]['duration_seconds'], 0.001);
    }

    public function test_get_saved_content(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $page = ContentPage::where('content_item_id', $item->id)->first();
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'batch_instruction' => '整批：暖色调转场',
            'narration' => '完整旁白',
            'units' => [$this->unitPayload($page->id, '缓慢推近', 4.5, '注意留白')],
        ])->assertOk();

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertOk()
            ->assertJsonPath('video.batch_instruction', '整批：暖色调转场')
            ->assertJsonPath('video.narration', '完整旁白')
            ->assertJsonPath('video.units.0.dynamic_instruction', '缓慢推近')
            ->assertJsonPath('video.units.0.duration_seconds', 4.5)
            ->assertJsonPath('video.units.0.note', '注意留白');
    }

    public function test_get_source_pages_auto_form_units_with_system_fields(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 3);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertOk()
            ->assertJsonCount(3, 'video.units')
            // 系统信息：序号、来源页、来源标签由后端补齐
            ->assertJsonPath('video.units.0.order', 1)
            ->assertJsonPath('video.units.0.source_label', 'P01 封面')
            ->assertJsonPath('video.units.1.order', 2)
            ->assertJsonPath('video.units.1.source_label', 'P02 正文')
            ->assertJsonPath('video.units.2.order', 3)
            ->assertJsonPath('video.units.2.source_label', 'P03 正文');
    }

    // ---------- PUT ----------

    public function test_put_creates_and_writes_first_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $page = ContentPage::where('content_item_id', $item->id)->first();
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'batch_instruction' => '说明',
            'narration' => '旁白',
            'units' => [$this->unitPayload($page->id, '动起来', 2.0)],
        ])->assertOk()->assertJsonPath('data.changed', true);

        $video = VideoContent::where('content_branch_id', $branch->id)->first();
        $this->assertNotNull($video);
        $this->assertSame('说明', $video->batch_instruction);
        $this->assertSame(1, $video->versions()->count());
        $this->assertSame(1, (int) $video->versions()->max('version_no'));
    }

    public function test_put_modify_creates_new_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";

        $this->putJson($url, ['narration' => 'v1 旁白'])->assertOk();
        $this->putJson($url, ['narration' => 'v2 旁白'])->assertOk()->assertJsonPath('data.changed', true);

        $video = VideoContent::where('content_branch_id', $branch->id)->first();
        $this->assertSame('v2 旁白', $video->narration);
        $this->assertSame(2, $video->versions()->count());
        $this->assertSame(2, (int) $video->versions()->max('version_no'));
    }

    public function test_unit_only_changes_persist_and_append_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $page = ContentPage::factory()->for($item)->create();
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";
        $this->putJson($url, ['units' => [$this->unitPayload($page->id, '原动态', 3)]])->assertOk();
        $this->putJson($url, ['units' => [$this->unitPayload($page->id, '新动态', 8, '停顿')]])
            ->assertOk()->assertJsonPath('data.changed', true);
        $this->getJson($url)->assertOk()->assertJsonPath('video.units.0.dynamic_instruction', '新动态')
            ->assertJsonPath('video.units.0.duration_seconds', 8)->assertJsonPath('video.units.0.note', '停顿');
        $this->assertSame(2, VideoContent::where('content_branch_id', $branch->id)->first()->versions()->count());
    }

    public function test_removed_source_page_does_not_block_future_saves(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $page = ContentPage::factory()->for($item)->create(['page_no' => 1]);
        $remaining = ContentPage::factory()->for($item)->create(['page_no' => 2]);
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";
        $this->putJson($url, ['units' => [$this->unitPayload($page->id, '旧动态', 3)]])->assertOk();
        $page->delete();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'video.units');
        $this->putJson($url, ['units' => [$this->unitPayload($remaining->id, '继续制作', 8)]])->assertOk();
        $this->getJson($url)->assertJsonPath('video.units.0.dynamic_instruction', '继续制作');
        $this->putJson($url, ['units' => [$this->unitPayload($page->id, '非法来源', 3)]])->assertUnprocessable();
    }

    public function test_put_rejects_unknown_fields_and_invented_unit_ids(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $page = ContentPage::factory()->for($item)->create();
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";
        $this->putJson($url, ['unknown' => 'x'])->assertUnprocessable();
        $this->putJson($url, ['units' => [['id' => 999, ...$this->unitPayload($page->id, '动态', 3)]]])->assertUnprocessable();
    }

    public function test_put_unchanged_creates_no_extra_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";

        $this->putJson($url, ['batch_instruction' => '说明', 'narration' => '旁白'])->assertOk();
        // 完全 unchanged
        $this->putJson($url, ['batch_instruction' => '说明', 'narration' => '旁白'])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $video = VideoContent::where('content_branch_id', $branch->id)->first();
        $this->assertSame(1, $video->versions()->count());
    }

    public function test_put_rejects_negative_duration(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $page = ContentPage::where('content_item_id', $item->id)->first();
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'units' => [$this->unitPayload($page->id, 'x', -1.0)],
        ])->assertUnprocessable()->assertJsonValidationErrors('units.0.duration_seconds');
    }

    public function test_put_accepts_valid_duration_and_zero(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $page = ContentPage::where('content_item_id', $item->id)->first();
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'units' => [$this->unitPayload($page->id, 'x', 0)],
        ])->assertOk();

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertOk()
            ->assertJsonPath('video.units.0.duration_seconds', 0);
    }

    public function test_put_rejects_unknown_source_page(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'units' => [$this->unitPayload(999999, 'x', 2.0)],
        ])->assertUnprocessable();
    }

    public function test_put_rejects_system_computed_fields(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->acting($item);

        // 客户端不得写 timeline / total_duration / order / title 等系统字段
        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'title' => '试图改标题',
        ])->assertUnprocessable();

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'total_duration' => 99,
        ])->assertUnprocessable();
    }

    // ---------- timeline / total_duration ----------

    public function test_timeline_and_total_duration_accumulate(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 3);
        $pages = ContentPage::where('content_item_id', $item->id)->orderBy('page_no')->get();
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video", [
            'units' => [
                $this->unitPayload($pages[0]->id, 'a', 2.0),
                $this->unitPayload($pages[1]->id, 'b', 3.5),
                $this->unitPayload($pages[2]->id, 'c', 1.25),
            ],
        ])->assertOk();

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertOk()
            // 2.0 + 3.5 + 1.25 = 6.75
            ->assertJsonPath('total_duration', 6.75)
            ->assertJsonCount(3, 'timeline')
            ->assertJsonPath('timeline.0.start_seconds', 0)
            ->assertJsonPath('timeline.0.end_seconds', 2)
            ->assertJsonPath('timeline.1.start_seconds', 2)
            ->assertJsonPath('timeline.1.end_seconds', 5.5)
            ->assertJsonPath('timeline.2.start_seconds', 5.5)
            ->assertJsonPath('timeline.2.end_seconds', 6.75)
            ->assertJsonPath('narration_note', '完整旁白对应整条视频；各视频单元的画面动效与时长独立维护。');
    }

    public function test_total_duration_uses_default_when_no_video_saved(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 2);
        $this->acting($item);

        // 未保存 → 2 个来源页 × 默认 3.0 秒 = 6.0
        $response = $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertOk();

        $this->assertEqualsWithDelta(6.0, $response->json('total_duration'), 0.001);
    }

    // ---------- source fingerprint / keep ----------

    public function test_source_changed_true_after_source_page_version_changes(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $page = ContentPage::where('content_item_id', $item->id)->first();
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";

        $this->putJson($url, [
            'units' => [$this->unitPayload($page->id, 'a', 2.0)],
        ])->assertOk();

        // 刚保存 → 未变化
        $this->getJson($url)->assertOk()->assertJsonPath('video.source_changed', false);

        // 来源页面产生新版本 → source_changed = true
        $page->versions()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'version_no' => 1,
            'page_no_snapshot' => $page->page_no,
            'page_type_snapshot' => $page->page_type->value,
            'final_copy' => 'updated copy',
        ]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('video.source_changed', true)
            ->assertJsonPath('video.source_change_summary.changed', true)
            ->assertJsonPath('video.source_change_summary.source_page_count', 1);
    }

    public function test_keep_source_resets_source_changed_without_touching_content(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->pages($item, 1);
        $page = ContentPage::where('content_item_id', $item->id)->first();
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video";

        $this->putJson($url, [
            'batch_instruction' => '原说明',
            'narration' => '原旁白',
            'units' => [$this->unitPayload($page->id, '原动效', 3.0)],
        ])->assertOk();

        $page->versions()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'version_no' => 1,
            'page_no_snapshot' => $page->page_no,
            'page_type_snapshot' => $page->page_type->value,
            'final_copy' => 'changed',
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('video.source_changed', true);

        // keep：只更新 fingerprint
        $this->postJson("{$url}/source-change", ['action' => 'keep'])->assertOk();

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('video.source_changed', false)
            // 内容未被改动
            ->assertJsonPath('video.batch_instruction', '原说明')
            ->assertJsonPath('video.narration', '原旁白')
            ->assertJsonPath('video.units.0.dynamic_instruction', '原动效');
    }

    public function test_keep_source_rejects_unknown_action(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video/source-change", [
            'action' => 'sync_all',
        ])->assertUnprocessable();
    }

    // ---------- 权限 ----------

    public function test_cross_project_returns_404(): void
    {
        $own = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $foreignBranch = $this->videoBranch($foreign);
        $this->acting($own);

        $this->getJson("/api/projects/{$foreign->project_id}/items/{$foreign->id}/branches/{$foreignBranch->id}/video")
            ->assertNotFound();
    }

    public function test_non_video_branch_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatChannels,
            'format' => ContentFormat::ImagePost,
        ]);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertUnprocessable();
    }

    public function test_non_wechat_channels_branch_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item, Channel::WechatOfficial->value, ContentFormat::Video->value);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertUnprocessable();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/video")
            ->assertUnauthorized();
    }
}
