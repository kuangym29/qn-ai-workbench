<?php

namespace Tests\Feature;

use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class V2ContentCoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_core_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('inspirations', [
            'id',
            'project_id',
            'body',
            'note',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('topics', [
            'inspiration_id',
            'status',
            'is_backup',
        ]));

        $this->assertTrue(Schema::hasColumns('content_items', [
            'core_message',
            'story_line',
        ]));

        $this->assertTrue(Schema::hasColumns('content_branches', [
            'id',
            'project_id',
            'content_item_id',
            'channel',
            'format',
            'stopped_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_content_branch_is_unique_per_item_channel_and_format(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->for($project)->for($column, 'contentColumn')->create();
        $item = ContentItem::factory()->for($project)->for($column, 'contentColumn')->for($topic)->create();

        $payload = [
            'project_id' => $project->id,
            'content_item_id' => $item->id,
            'channel' => 'wechat_official',
            'format' => 'image_post',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('content_branches')->insert($payload);

        $this->expectException(QueryException::class);
        DB::table('content_branches')->insert($payload);
    }

    public function test_one_topic_can_only_have_one_content_item(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->for($project)->for($column, 'contentColumn')->create();

        ContentItem::factory()->for($project)->for($column, 'contentColumn')->for($topic)->create();

        $this->expectException(QueryException::class);
        ContentItem::factory()->for($project)->for($column, 'contentColumn')->for($topic)->create();
    }

    public function test_referenced_inspiration_cannot_delete_a_topics_project(): void
    {
        $topic = Topic::factory()->create();
        $id = DB::table('inspirations')->insertGetId([
            'project_id' => $topic->project_id, 'body' => 'A captured idea',
        ]);
        DB::table('topics')->where('id', $topic->id)->update(['inspiration_id' => $id]);

        try {
            DB::table('inspirations')->where('id', $id)->delete();
            $this->fail('Referenced inspiration deletion must be restricted.');
        } catch (QueryException $exception) {
            // A NOT NULL error means SET NULL tried to erase project_id: wrong contract.
            $this->assertStringContainsString('foreign key constraint', strtolower($exception->getMessage()));
        }
        $this->assertDatabaseHas('topics', ['id' => $topic->id, 'project_id' => $topic->project_id]);
        $this->assertDatabaseHas('inspirations', ['id' => $id]);
    }

    public function test_topic_cannot_reference_another_projects_inspiration(): void
    {
        $topic = Topic::factory()->create();
        $id = DB::table('inspirations')->insertGetId([
            'project_id' => Project::factory()->create()->id, 'body' => 'Other project',
        ]);
        $this->expectException(QueryException::class);
        DB::table('topics')->where('id', $topic->id)->update(['inspiration_id' => $id]);
    }

    public function test_branch_cannot_reference_another_projects_content_item(): void
    {
        $item = ContentItem::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('content_branches')->insert([
            'project_id' => Project::factory()->create()->id,
            'content_item_id' => $item->id, 'channel' => 'wechat_official', 'format' => 'article',
        ]);
    }

    public function test_stopped_branch_still_reserves_its_channel_and_format(): void
    {
        $item = ContentItem::factory()->create();
        $payload = [
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'channel' => 'wechat_official', 'format' => 'article', 'stopped_at' => now(),
        ];
        DB::table('content_branches')->insert($payload);
        $payload['stopped_at'] = null;
        $this->expectException(QueryException::class);
        DB::table('content_branches')->insert($payload);
    }

    public function test_distinct_formats_share_one_content_item_and_direct_topic_defaults(): void
    {
        $item = ContentItem::factory()->create();
        $topic = DB::table('topics')->find($item->topic_id);
        $this->assertNull($topic->inspiration_id);
        $this->assertSame('pending', $topic->status);
        $this->assertFalse((bool) $topic->is_backup);
        foreach (['image_post', 'article'] as $format) {
            DB::table('content_branches')->insert([
                'project_id' => $item->project_id, 'content_item_id' => $item->id,
                'channel' => 'wechat_official', 'format' => $format,
            ]);
        }
        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseCount('content_branches', 2);
    }
}
