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
}
