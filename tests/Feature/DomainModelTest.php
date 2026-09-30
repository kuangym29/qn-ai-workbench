<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\ChannelTask;
use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_tables_and_relationships_follow_the_project_hierarchy(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->for($project)->for($column, 'contentColumn')->create();
        $item = ContentItem::factory()->for($project)->for($column, 'contentColumn')->for($topic)->create();
        $production = ProductionTask::factory()->for($project)->for($item, 'contentItem')->create();
        $channel = ChannelTask::factory()->for($project)->for($production, 'productionTask')->create();

        $this->assertTrue(Schema::hasTable('content_columns'));
        $this->assertFalse(Schema::hasTable('columns'));
        $this->assertSame($column->id, $project->contentColumns->first()->id);
        $this->assertSame($topic->id, $column->topics->first()->id);
        $this->assertSame($item->id, $topic->contentItems->first()->id);
        $this->assertSame($production->id, $item->productionTask->id);
        $this->assertSame($channel->id, $production->channelTasks->first()->id);
        $this->assertSame($project->id, $channel->productionTask->contentItem->project->id);
    }

    public function test_topic_cannot_reference_a_column_in_another_project(): void
    {
        $project = Project::factory()->create();
        $foreignColumn = ContentColumn::factory()->create();

        $this->expectException(QueryException::class);
        Topic::factory()->for($project)->for($foreignColumn, 'contentColumn')->create();
    }

    public function test_content_item_cannot_reference_a_topic_in_another_project(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $foreignTopic = Topic::factory()->create();

        $this->expectException(QueryException::class);
        ContentItem::factory()->for($project)->for($column, 'contentColumn')->for($foreignTopic, 'topic')->create();
    }

    public function test_content_item_topic_must_belong_to_its_column(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $otherColumn = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->for($project)->for($otherColumn, 'contentColumn')->create();

        $this->expectException(QueryException::class);
        ContentItem::factory()->for($project)->for($column, 'contentColumn')->for($topic)->create();
    }

    public function test_production_and_channel_tasks_cannot_cross_projects(): void
    {
        $project = Project::factory()->create();
        $foreignItem = ContentItem::factory()->create();

        try {
            ProductionTask::factory()->for($project)->for($foreignItem, 'contentItem')->create();
            $this->fail('Cross-project production task was accepted.');
        } catch (QueryException) {
            // The composite foreign key must reject this reference.
        }

        $foreignProduction = ProductionTask::factory()->create();
        $this->expectException(QueryException::class);
        ChannelTask::factory()->for($project)->for($foreignProduction, 'productionTask')->create();
    }

    public function test_copy_artwork_video_and_publish_states_are_separate(): void
    {
        $item = ContentItem::factory()->create();
        $production = ProductionTask::factory()->for($item->project)->for($item, 'contentItem')->create();
        $official = ChannelTask::factory()->for($item->project)->for($production, 'productionTask')->create();
        $channels = ChannelTask::factory()->for($item->project)->for($production, 'productionTask')->wechatChannels()->create();

        $this->assertSame('draft', $item->copy_status);
        $this->assertSame('not_started', $production->artwork_status);
        $this->assertSame(Channel::WechatOfficial, $official->channel);
        $this->assertSame('wechat_official', $official->getRawOriginal('channel'));
        $this->assertNull($official->video_status);
        $this->assertSame(Channel::WechatChannels, $channels->channel);
        $this->assertSame('not_started', $channels->video_status);
        $this->assertSame('not_published', $channels->publish_status);
    }

    public function test_unknown_channel_is_rejected_by_the_model_without_a_database_enum(): void
    {
        $this->expectException(\ValueError::class);
        ChannelTask::factory()->state(['channel' => 'unknown_channel'])->create();
    }

    public function test_one_content_item_has_one_shared_production_task(): void
    {
        $item = ContentItem::factory()->create();
        ProductionTask::factory()->for($item->project)->for($item, 'contentItem')->create();

        $this->expectException(QueryException::class);
        ProductionTask::factory()->for($item->project)->for($item, 'contentItem')->create();
    }

    public function test_channel_task_is_unique_per_shared_production_and_channel(): void
    {
        $production = ProductionTask::factory()->create();
        ChannelTask::factory()->for($production->project)->for($production, 'productionTask')->create();

        $this->expectException(QueryException::class);
        ChannelTask::factory()->for($production->project)->for($production, 'productionTask')->create();
    }
}
