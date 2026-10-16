<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V2 基础迁移（content_branches / inspirations / topics.inspiration_id / content_items.core_message）的回滚与重建。
 *
 * 不依赖"最后 N 个 migration 恰好是谁"：用 migrate:reset（回滚全部）/ migrate（重建）验证
 * V2 schema 能正确回滚与重新应用，顺序无关。历史业务数据允许清空，故不再断言 legacy 行保留。
 */
class V2MigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_v2_migrations_rollback_and_reapply(): void
    {
        // DatabaseMigrations 已在测试前完成全量迁移。
        $this->assertTrue(Schema::hasTable('content_branches'));
        $this->assertTrue(Schema::hasColumn('content_items', 'core_message'));

        // 顺序无关：回滚全部迁移（不依赖 --step=N）。
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('content_branches'));
        $this->assertFalse(Schema::hasColumn('content_items', 'core_message'));

        // 重新迁移（等价于 fresh），V2 schema 必须能正确重建。
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('content_branches'));
        $this->assertTrue(Schema::hasColumn('content_items', 'core_message'));
    }
}
