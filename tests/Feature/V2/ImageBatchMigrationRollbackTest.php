<?php

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 图片批次迁移：顺序无关（不使用 --step=N），支持 fresh / reset / rollback / re-migrate。
 *
 * 用 DatabaseMigrations（与 ImagePostMigrationRollbackTest 一致）：
 * migrate:fresh 会触发 SQLite VACUUM，不能跑在 RefreshDatabase 的事务里。
 */
class ImageBatchMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const TABLES = ['image_batch_drafts', 'image_batch_unit_settings', 'image_batch_runs'];

    public function test_migrations_created_all_image_batch_tables(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' 必须存在');
        }

        foreach (['batch_instruction', 'batch_note', 'content_branch_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('image_batch_drafts', $col));
        }
        foreach (['image_batch_draft_id', 'content_page_id', 'special_requirement', 'source_fingerprint', 'status'] as $col) {
            $this->assertTrue(Schema::hasColumn('image_batch_unit_settings', $col));
        }
        foreach (['image_batch_draft_id', 'mode', 'unit_ids', 'status', 'instruction_snapshot', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('image_batch_runs', $col));
        }
    }

    public function test_fresh_rebuilds_image_batch_schema(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_reset_drops_image_batch_tables(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' 应已被 reset 清空');
        }
    }

    public function test_rollback_and_remigrate_image_batch_schema(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' re-migrate 后必须存在');
        }
    }

    public function test_remigrate_after_reset_rebuilds_schema(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
