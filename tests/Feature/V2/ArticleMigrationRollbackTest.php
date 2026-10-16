<?php

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RED: article_contents / article_content_versions 迁移尚未实现，这些断言必须先失败。
 *
 * 顺序无关（不依赖 --step=N）：用 migrate:reset / migrate:fresh 验证 B02 两张表的
 * up()/down() 正确，再循环回滚直到两张表彻底消失、再 re-migrate 恢复。
 */
class ArticleMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const TABLES = ['article_contents', 'article_content_versions'];

    public function test_article_tables_exist_after_migrate(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} 应存在");
        }
        $this->assertTrue(Schema::hasColumn('article_contents', 'content_branch_id'));
        $this->assertTrue(Schema::hasColumn('article_contents', 'image_suggestions'));
        $this->assertTrue(Schema::hasColumn('article_content_versions', 'snapshot'));
    }

    public function test_reset_then_migrate_restores_article_tables(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "reset 后 {$table} 应不存在");
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "re-migrate 后 {$table} 应恢复");
        }
    }

    public function test_rollback_removes_article_tables_and_remigrate_restores(): void
    {
        // 持续回滚直到 B02 表彻底消失，不依赖"最后 N 个迁移"。
        $guard = 0;
        while (Schema::hasTable('article_contents') || Schema::hasTable('article_content_versions')) {
            $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
            if (++$guard > 200) {
                $this->fail('回滚未在合理步数内移除 article 表');
            }
        }
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
