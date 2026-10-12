<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class V2MigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_v2_migrations_rollback_and_reapply_preserving_legacy_rows(): void
    {
        $item = ContentItem::factory()->create();
        $this->artisan('migrate:rollback', ['--step' => 4, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('content_branches'));
        $this->assertFalse(Schema::hasTable('inspirations'));
        $this->assertFalse(Schema::hasColumn('topics', 'inspiration_id'));
        $this->assertFalse(Schema::hasColumn('content_items', 'core_message'));
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'title' => $item->title]);

        // The unique topic rule must also be removed on rollback.
        $legacyDuplicate = DB::table('content_items')->insertGetId([
            'project_id' => $item->project_id, 'content_column_id' => $item->content_column_id,
            'topic_id' => $item->topic_id, 'title' => 'Rollback duplicate probe',
        ]);
        DB::table('content_items')->where('id', $legacyDuplicate)->delete();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('content_branches'));
        $this->assertTrue(Schema::hasColumn('topics', 'inspiration_id'));
        $this->assertTrue(Schema::hasColumn('content_items', 'core_message'));
        $this->assertDatabaseHas('content_items', ['id' => $item->id]);
    }
}
