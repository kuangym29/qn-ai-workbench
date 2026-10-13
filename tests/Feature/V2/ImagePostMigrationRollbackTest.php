<?php

namespace Tests\Feature\V2;

use App\Models\ContentItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RED: the B01 migrations do not exist yet — these must fail until GREEN.
 *
 * 新增字段（B01 范围内的最小集合，不做万能扩展）：
 *   content_pages:    narrative_role, new_fact, final_copy, visual_evidence, continuity, production_note
 *   content_page_versions (快照): 同名 6 列
 */
class ImagePostMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const PAGE_FIELDS = [
        'narrative_role', 'new_fact', 'final_copy', 'visual_evidence', 'continuity', 'production_note',
    ];

    public function test_image_post_columns_present_after_migrate_and_removed_after_rollback(): void
    {
        foreach (self::PAGE_FIELDS as $field) {
            $this->assertTrue(
                Schema::hasColumn('content_pages', $field),
                "content_pages.{$field} 应在 migrate 后存在"
            );
            $this->assertTrue(
                Schema::hasColumn('content_page_versions', $field),
                "content_page_versions.{$field} 应在 migrate 后存在"
            );
        }

        $item = ContentItem::factory()->create();
        $this->assertDatabaseHas('content_items', ['id' => $item->id]);

        // B01 迁移置于 2026_10_02_000022/023，紧随其后的是 4 个 V2 基础迁移，
        // 因此回滚 6 步才能覆盖到 B01 的两条（验证 down 正确回收字段）。
        $this->artisan('migrate:rollback', ['--step' => 6, '--force' => true])->assertExitCode(0);

        foreach (self::PAGE_FIELDS as $field) {
            $this->assertFalse(
                Schema::hasColumn('content_pages', $field),
                "content_pages.{$field} 应在 rollback 后移除"
            );
            $this->assertFalse(
                Schema::hasColumn('content_page_versions', $field),
                "content_page_versions.{$field} 应在 rollback 后移除"
            );
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        foreach (self::PAGE_FIELDS as $field) {
            $this->assertTrue(Schema::hasColumn('content_pages', $field));
            $this->assertTrue(Schema::hasColumn('content_page_versions', $field));
        }
    }
}
