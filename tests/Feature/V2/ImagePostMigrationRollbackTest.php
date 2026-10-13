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

        // 顺序无关：持续回滚直到 B01 标志列消失（不依赖"最后 N 个 migration"）。
        $steps = 0;
        while (Schema::hasColumn('content_pages', 'narrative_role') && $steps < 80) {
            $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
            $steps++;
        }

        foreach (self::PAGE_FIELDS as $field) {
            $this->assertFalse(
                Schema::hasColumn('content_pages', $field),
                "content_pages.{$field} 应在 rollback 后移除（down 正确）"
            );
            $this->assertFalse(
                Schema::hasColumn('content_page_versions', $field),
                "content_page_versions.{$field} 应在 rollback 后移除（down 正确）"
            );
        }

        // 明确验证 migrate:fresh 能重建正确的 B01 schema（顺序无关）。
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        foreach (self::PAGE_FIELDS as $field) {
            $this->assertTrue(Schema::hasColumn('content_pages', $field), "content_pages.{$field} 应在 fresh 后存在");
            $this->assertTrue(Schema::hasColumn('content_page_versions', $field), "content_page_versions.{$field} 应在 fresh 后存在");
        }
    }
}
