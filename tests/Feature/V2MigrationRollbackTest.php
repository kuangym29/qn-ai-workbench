<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V2 基础迁移回滚稳定性测试（顺序无关，不依赖 --step=N）。
 *
 * 历史业务数据允许清空，因此不再断言 legacy 行保留；只验证：
 *   - migrate:reset 后 V2 关键表全部消失；
 *   - migrate 后 V2 关键表全部恢复（up() 正确）。
 * 任何新迁移（含 B02 的 article 表）都通过各自的 ArticleMigrationRollbackTest 独立验证，
 * 本测试只关心 V2 基础 schema 的可逆性。
 */
class V2MigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const V2_MARKERS = [
        'content_branches',
        'inspirations',
        'topics',
        'content_items',
    ];

    private const V2_COLUMNS = [
        ['topics', 'inspiration_id'],
        ['content_items', 'core_message'],
    ];

    public function test_v2_schema_rollback_and_reapply_is_order_independent(): void
    {
        foreach (self::V2_MARKERS as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        foreach (self::V2_COLUMNS as [$table, $column]) {
            $this->assertTrue(Schema::hasColumn($table, $column));
        }

        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        foreach (self::V2_MARKERS as $table) {
            $this->assertFalse(Schema::hasTable($table), "reset 后 {$table} 应不存在");
        }
        foreach (self::V2_COLUMNS as [$table, $column]) {
            $this->assertFalse(Schema::hasColumn($table, $column));
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach (self::V2_MARKERS as $table) {
            $this->assertTrue(Schema::hasTable($table), "re-migrate 后 {$table} 应恢复");
        }
        foreach (self::V2_COLUMNS as [$table, $column]) {
            $this->assertTrue(Schema::hasColumn($table, $column));
        }
    }
}
