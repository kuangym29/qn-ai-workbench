<?php

namespace Tests\Feature;

use App\Enums\ArtworkStatus;
use App\Enums\CopyStatus;
use App\Enums\PublishStatus;
use App\Enums\VideoStatus;
use App\Models\ChannelTask;
use App\Models\ContentItem;
use App\Models\ProductionTask;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusBackfillTest extends TestCase
{
    use DatabaseMigrations;

    public function test_legacy_values_are_backfilled_before_enum_cast_reads_them(): void
    {
        $item = ContentItem::factory()->create();
        $production = ProductionTask::factory()->for($item->project)->for($item, 'contentItem')->create();
        $channel = ChannelTask::factory()->for($item->project)->for($production, 'productionTask')->create();

        $migration = require database_path('migrations/2026_10_01_000008_normalize_status_defaults.php');
        $migration->down();

        DB::table('content_items')->where('id', $item->id)->update(['copy_status' => 'draft']);
        DB::table('channel_tasks')->where('id', $channel->id)->update([
            'video_status' => null,
            'publish_status' => 'not_published',
        ]);

        $migration->up();

        $this->assertSame(CopyStatus::NotStarted, $item->refresh()->copy_status);
        $this->assertSame(ArtworkStatus::NotStarted, $production->refresh()->artwork_status);
        $this->assertSame(VideoStatus::NotApplicable, $channel->refresh()->video_status);
        $this->assertSame(PublishStatus::Unpublished, $channel->publish_status);
        $this->assertSame('not_started', DB::table('content_items')->where('id', $item->id)->value('copy_status'));
        $this->assertSame('not_applicable', DB::table('channel_tasks')->where('id', $channel->id)->value('video_status'));
        $this->assertSame('unpublished', DB::table('channel_tasks')->where('id', $channel->id)->value('publish_status'));

        $newItemId = DB::table('content_items')->insertGetId([
            'project_id' => $item->project_id,
            'content_column_id' => $item->content_column_id,
            'topic_id' => $item->topic_id,
            'title' => '数据库默认状态验证',
        ]);
        $newProductionId = DB::table('production_tasks')->insertGetId([
            'project_id' => $item->project_id,
            'content_item_id' => $newItemId,
        ]);
        $newChannelId = DB::table('channel_tasks')->insertGetId([
            'project_id' => $item->project_id,
            'production_task_id' => $newProductionId,
            'channel' => 'wechat_official',
        ]);

        $this->assertSame('not_started', DB::table('content_items')->where('id', $newItemId)->value('copy_status'));
        $this->assertSame('not_started', DB::table('production_tasks')->where('id', $newProductionId)->value('artwork_status'));
        $this->assertSame('not_applicable', DB::table('channel_tasks')->where('id', $newChannelId)->value('video_status'));
        $this->assertSame('unpublished', DB::table('channel_tasks')->where('id', $newChannelId)->value('publish_status'));
    }
}
