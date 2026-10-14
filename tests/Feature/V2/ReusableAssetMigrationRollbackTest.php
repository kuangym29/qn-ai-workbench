<?php

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 迁移契约（顺序无关）：
 *   migrate            → reusable_assets 存在且字段正确
 *   rollback（持续）   → 表被 dropIfExists 移除
 *   migrate:reset      → 全部回滚后同样消失
 *   migrate:fresh      → 能完整重建
 *   migrate（re-migrate）→ 再次可用，且唯一索引就位
 */
class ReusableAssetMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const COLUMNS = [
        'id', 'project_id', 'type', 'name', 'status',
        'thumbnail_url', 'description', 'last_used_at', 'created_at', 'updated_at',
    ];

    public function test_reusable_assets_table_survives_fresh_rollback_and_re_migrate(): void
    {
        $this->assertTrue(Schema::hasTable('reusable_assets'), 'reusable_assets 应在 migrate 后存在');
        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('reusable_assets', $column), "reusable_assets.{$column} 应存在");
        }

        // 顺序无关：持续回滚直到本模块的表消失（不依赖「最后 1 个 migration」）。
        $steps = 0;
        while (Schema::hasTable('reusable_assets') && $steps < 80) {
            $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
            $steps++;
        }

        $this->assertFalse(Schema::hasTable('reusable_assets'), 'rollback 后 reusable_assets 应被移除（down 正确）');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('reusable_assets'), 'migrate:fresh 后 reusable_assets 应重建');
        $this->assertContains('reusable_assets_project_type_name_unique', $this->indexNames());
    }

    public function test_reset_all_then_re_migrate_restores_table(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('reusable_assets'), 'migrate:reset 后 reusable_assets 应被移除');

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('reusable_assets'), 're-migrate 后 reusable_assets 应恢复');
        $this->assertContains('reusable_assets_project_type_name_unique', $this->indexNames());
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_column(Schema::getIndexes('reusable_assets'), 'name');
    }
}
