<?php

namespace Tests\Feature\V2;

use App\Models\VideoContent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B04 迁移：顺序无关（不使用 --step=N），必须支持 migrate:fresh / migrate:reset /
 * rollback / re-migrate。
 *
 * 新增表：video_contents、video_content_versions。
 */
class VideoMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_video_tables_present_after_migrate_and_removed_after_rollback(): void
    {
        $this->assertTrue(Schema::hasTable('video_contents'));
        $this->assertTrue(Schema::hasTable('video_content_versions'));

        foreach (['batch_instruction', 'narration', 'units', 'source_fingerprint'] as $col) {
            $this->assertTrue(Schema::hasColumn('video_contents', $col), "video_contents.{$col} 应存在");
        }
        foreach (['version_no', 'snapshot', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('video_content_versions', $col), "video_content_versions.{$col} 应存在");
        }

        VideoContent::factory()->create();
        $this->assertDatabaseHas('video_contents', ['id' => VideoContent::first()->id]);

        // 顺序无关：持续回滚直到两张 B04 表都消失（不依赖"最后 N 个 migration"）。
        $steps = 0;
        while ((Schema::hasTable('video_contents') || Schema::hasTable('video_content_versions')) && $steps < 200) {
            $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
            $steps++;
        }

        $this->assertFalse(Schema::hasTable('video_contents'), 'rollback 后 video_contents 应被删除');
        $this->assertFalse(Schema::hasTable('video_content_versions'), 'rollback 后 video_content_versions 应被删除');

        // 重新迁移（顺序无关）
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('video_contents'));
        $this->assertTrue(Schema::hasTable('video_content_versions'));
    }

    public function test_reset_and_fresh_rebuild_video_schema(): void
    {
        // migrate:reset 回滚全部迁移 → 全库清空，再 migrate 重建（顺序无关）。
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('video_contents'), 'reset 后应清空');

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('video_contents'));
        $this->assertTrue(Schema::hasTable('video_content_versions'));

        // migrate:fresh 直接重建完整 schema。
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('video_contents'));
        $this->assertTrue(Schema::hasTable('video_content_versions'));
        foreach (['batch_instruction', 'narration', 'units', 'source_fingerprint'] as $col) {
            $this->assertTrue(Schema::hasColumn('video_contents', $col));
        }
    }
}
