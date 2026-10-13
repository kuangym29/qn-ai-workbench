<?php

namespace Tests\Feature\V2;

use App\Models\ReviewRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B03 迁移：顺序无关（不使用 --step=N），必须支持 migrate:fresh / migrate:reset /
 * rollback / re-migrate。
 *
 * 新增表：review_requests、review_decisions。
 */
class ReviewMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_review_tables_present_after_migrate_and_removed_after_rollback(): void
    {
        $this->assertTrue(Schema::hasTable('review_requests'));
        $this->assertTrue(Schema::hasTable('review_decisions'));

        foreach (['review_target_type', 'review_target_label', 'version_label', 'preview', 'status', 'submitted_at', 'decided_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('review_requests', $col), "review_requests.{$col} 应存在");
        }
        foreach (['decision_no', 'decision', 'comment', 'target_label', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('review_decisions', $col), "review_decisions.{$col} 应存在");
        }

        ReviewRequest::factory()->create();
        $this->assertDatabaseHas('review_requests', ['id' => ReviewRequest::first()->id]);

        // 顺序无关：持续回滚直到两张 B03 表都消失（不依赖"最后 N 个 migration"）。
        $steps = 0;
        while ((Schema::hasTable('review_requests') || Schema::hasTable('review_decisions')) && $steps < 200) {
            $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
            $steps++;
        }

        $this->assertFalse(Schema::hasTable('review_requests'), 'rollback 后 review_requests 应被删除');
        $this->assertFalse(Schema::hasTable('review_decisions'), 'rollback 后 review_decisions 应被删除');

        // migrate:reset 重建后也能重新迁移（顺序无关）。
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('review_requests'));
        $this->assertTrue(Schema::hasTable('review_decisions'));
    }

    public function test_fresh_rebuilds_review_schema(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('review_requests'));
        $this->assertTrue(Schema::hasTable('review_decisions'));
        foreach (['review_target_type', 'review_target_label', 'version_label', 'preview', 'status'] as $col) {
            $this->assertTrue(Schema::hasColumn('review_requests', $col));
        }
    }
}
