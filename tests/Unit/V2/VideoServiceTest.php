<?php

namespace Tests\Unit\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\VideoContent;
use App\Services\V2\VideoManualService;
use App\Services\V2\VideoSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * VideoSourceService / VideoManualService 的领域不变量。
 */
class VideoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function videoBranch(ContentItem $item): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatChannels,
            'format' => ContentFormat::Video,
        ]);
    }

    private function seedPages(ContentItem $item, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ContentPage::factory()->for($item)->create([
                'page_no' => $i,
                'page_type' => $i === 1 ? 'cover' : 'content',
            ]);
        }
    }

    public function test_units_are_derived_from_source_pages_in_page_order(): void
    {
        $item = ContentItem::factory()->create();
        $this->seedPages($item, 2);

        $units = (new VideoSourceService)->units($item, null);

        $this->assertCount(2, $units);
        $this->assertSame(1, $units[0]['order']);
        $this->assertSame('P01 封面', $units[0]['source_label']);
        $this->assertSame(2, $units[1]['order']);
        $this->assertSame('P02 正文', $units[1]['source_label']);
        // 未设置 → 空制作信息 + 合理默认值
        $this->assertSame('', $units[0]['dynamic_instruction']);
        $this->assertSame(VideoSourceService::DEFAULT_DURATION_SECONDS, $units[0]['duration_seconds']);
        $this->assertNull($units[0]['note']);
    }

    public function test_new_source_page_appears_with_empty_production_info(): void
    {
        $item = ContentItem::factory()->create();
        $this->seedPages($item, 1);
        $branch = $this->videoBranch($item);
        (new VideoManualService)->save($branch, ['narration' => '旁白']);

        // 来源新增一页（用户不需要重复建立来源页）
        ContentPage::factory()->for($item)->create(['page_no' => 2, 'page_type' => 'content']);

        $units = (new VideoSourceService)->units($item, VideoContent::where('content_branch_id', $branch->id)->first());

        $this->assertCount(2, $units);
        $this->assertSame('', $units[1]['dynamic_instruction']);
        $this->assertSame(VideoSourceService::DEFAULT_DURATION_SECONDS, $units[1]['duration_seconds']);
    }

    public function test_source_changed_false_when_video_not_saved(): void
    {
        $item = ContentItem::factory()->create();
        $this->seedPages($item, 1);

        $this->assertFalse((new VideoSourceService)->sourceChanged(null, $item));
    }

    public function test_fingerprint_reflects_source_page_versions_only(): void
    {
        $item = ContentItem::factory()->create();
        $this->seedPages($item, 1);
        $service = new VideoSourceService;
        $before = $service->currentFingerprint($item);

        $page = ContentPage::where('content_item_id', $item->id)->first();
        $page->versions()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'version_no' => 1,
            'page_no_snapshot' => $page->page_no,
            'page_type_snapshot' => 'content',
        ]);

        $this->assertNotSame($before, $service->currentFingerprint($item));
    }

    public function test_save_rejects_non_video_branch(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatChannels,
            'format' => ContentFormat::ImagePost,
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new VideoManualService)->save($branch, ['narration' => 'x']);
    }

    public function test_save_rejects_non_wechat_channels_branch(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::Xiaohongshu,
            'format' => ContentFormat::Video,
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new VideoManualService)->save($branch, ['narration' => 'x']);
    }

    public function test_save_rejects_unit_from_foreign_source_page(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->videoBranch($item);
        $foreignItem = ContentItem::factory()->create();
        $foreignPage = ContentPage::factory()->for($foreignItem)->create(['page_no' => 1]);

        $this->expectException(InvalidArgumentException::class);
        (new VideoManualService)->save($branch, [
            'units' => [[
                'source_page_id' => $foreignPage->id,
                'dynamic_instruction' => 'x',
                'duration_seconds' => 2,
            ]],
        ]);
    }

    public function test_timeline_accumulates_and_normalizes_negative(): void
    {
        $service = new VideoManualService;
        [$timeline, $total] = $service->timeline([
            ['order' => 1, 'source_page_id' => 1, 'source_label' => 'P01', 'duration_seconds' => 2],
            ['order' => 2, 'source_page_id' => 2, 'source_label' => 'P02', 'duration_seconds' => -5],
            ['order' => 3, 'source_page_id' => 3, 'source_label' => 'P03', 'duration_seconds' => 1.5],
        ]);

        $this->assertSame(0.0, $timeline[0]['start_seconds']);
        $this->assertSame(2.0, $timeline[0]['end_seconds']);
        // 负数归零（负数在 API 层已被 422 拦截，此处为防御性归一）
        $this->assertSame(2.0, $timeline[1]['start_seconds']);
        $this->assertSame(2.0, $timeline[1]['end_seconds']);
        $this->assertSame(3.5, $total);
    }

    public function test_keep_source_does_not_touch_content(): void
    {
        $item = ContentItem::factory()->create();
        $this->seedPages($item, 1);
        $branch = $this->videoBranch($item);
        $page = ContentPage::where('content_item_id', $item->id)->first();

        (new VideoManualService)->save($branch, [
            'batch_instruction' => '说明',
            'narration' => '旁白',
            'units' => [[
                'source_page_id' => $page->id,
                'dynamic_instruction' => '动效',
                'duration_seconds' => 4,
            ]],
        ]);

        $page->versions()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'version_no' => 1,
            'page_no_snapshot' => $page->page_no,
            'page_type_snapshot' => 'content',
        ]);

        (new VideoManualService)->keepSource($branch);

        $video = VideoContent::where('content_branch_id', $branch->id)->first();
        $this->assertSame('说明', $video->batch_instruction);
        $this->assertSame('旁白', $video->narration);
        $this->assertSame('动效', $video->units[0]['dynamic_instruction']);
        $this->assertFalse((new VideoSourceService)->sourceChanged($video, $item));
        // keep 不产生新版本
        $this->assertSame(1, $video->versions()->count());
    }

    public function test_one_video_per_branch_unique(): void
    {
        $item = ContentItem::factory()->create();
        $this->seedPages($item, 1);
        $branch = $this->videoBranch($item);

        (new VideoManualService)->save($branch, ['narration' => '第一次']);
        (new VideoManualService)->save($branch, ['narration' => '第二次']);

        // 一个分支一份视频制作（UNIQUE content_branch_id）
        $this->assertSame(1, VideoContent::where('content_branch_id', $branch->id)->count());
        $this->assertSame('第二次', VideoContent::where('content_branch_id', $branch->id)->first()->narration);
    }
}
