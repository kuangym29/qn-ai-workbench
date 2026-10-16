<?php

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionStage2BMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_combined_schema_survives_fresh_reset_rollback_and_remigrate(): void
    {
        // DatabaseMigrations has already run migrate:fresh for the combined schema.
        $tables = ['inspirations', 'topics', 'content_items', 'content_branches', 'content_pages',
            'article_contents', 'article_content_versions', 'video_contents', 'video_content_versions',
            'image_batch_drafts', 'image_batch_unit_settings', 'image_batch_runs',
            'reusable_assets', 'review_requests', 'review_decisions'];
        $assertTables = function (bool $present) use ($tables): void {
            foreach ($tables as $table) {
                $this->assertSame($present, Schema::hasTable($table), $table);
            }
        };
        $assertTables(true);
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $assertTables(false);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $assertTables(true);
        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
        $assertTables(false);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $assertTables(true);
    }
}
