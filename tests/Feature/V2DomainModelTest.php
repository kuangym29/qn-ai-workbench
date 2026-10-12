<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\TopicStatus;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Inspiration;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_source_relations_and_shared_content_are_persisted(): void
    {
        $inspiration = Inspiration::factory()->create();
        $topic = Topic::factory()->for($inspiration->project)->for($inspiration)->create();
        $item = ContentItem::factory()->for($topic)->create([
            'project_id' => $topic->project_id, 'content_column_id' => $topic->content_column_id,
            'core_message' => 'Shared message', 'story_line' => 'Shared story',
        ]);
        foreach ([ContentFormat::ImagePost, ContentFormat::Article] as $format) {
            ContentBranch::factory()->for($item)->create(['format' => $format]);
        }
        $this->assertSame(TopicStatus::Pending, $topic->refresh()->status);
        $this->assertFalse($topic->is_backup);
        $this->assertSame($inspiration->id, $topic->inspiration->id);
        $this->assertSame($topic->id, $inspiration->topics->sole()->id);
        $this->assertSame($item->id, $topic->contentItem->id);
        $this->assertCount(2, $item->contentBranches);
        $this->assertSame('Shared message', $item->refresh()->core_message);
        $this->assertSame('Shared story', $item->story_line);
        $this->assertCount(1, $inspiration->project->inspirations);
        $this->assertCount(2, $inspiration->project->contentBranches);
        $this->assertSame($item->id, $item->contentColumn->contentItems->sole()->id);
        foreach ($item->contentBranches as $branch) {
            $this->assertSame($item->project_id, $branch->project_id);
            $this->assertSame($item->id, $branch->contentItem->id);
            $this->assertSame(Channel::WechatOfficial, $branch->channel);
            $this->assertInstanceOf(ContentFormat::class, $branch->format);
        }
    }

    public function test_direct_topic_can_be_parked_and_starred_without_fabricated_source(): void
    {
        $topic = Topic::factory()->create(['status' => TopicStatus::Parked, 'is_backup' => true]);
        $this->assertSame(TopicStatus::Parked, $topic->refresh()->status);
        $this->assertTrue($topic->is_backup);
        $this->assertNull($topic->inspiration);
        $this->assertNull($topic->contentItem);
        $this->assertDatabaseCount('inspirations', 0);
        $this->assertDatabaseCount('source_references', 0);
    }

    public function test_stop_and_resume_keep_identity_history_and_other_content(): void
    {
        $branch = ContentBranch::factory()->create();
        $identity = [$branch->id, $branch->content_item_id, $branch->project_id, $branch->channel, $branch->format];
        $this->assertFalse($branch->isStopped());
        $this->freezeTime();
        $branch->stop();
        $stoppedAt = $branch->refresh()->stopped_at;
        $this->assertTrue($branch->isStopped());
        $this->travel(1)->hour();
        $branch->stop();
        $this->assertTrue($branch->refresh()->stopped_at->equalTo($stoppedAt));
        $branch->resume();
        $this->assertFalse($branch->refresh()->isStopped());
        $this->assertNull($branch->stopped_at);
        $this->assertSame($identity, [$branch->id, $branch->content_item_id, $branch->project_id, $branch->channel, $branch->format]);
        $this->assertDatabaseCount('content_branches', 1);
        $this->assertDatabaseCount('content_items', 1);
    }
}
