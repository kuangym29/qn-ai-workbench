<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B01（2026-10-13 开发）：小绿书（image_post）每页 8 个正式字段中的 6 个内容字段。
 * page_no / page_type 已存在，这里补齐其余 6 个。
 * 不做任何"万能扩展"字段。
 *
 * 时间戳置于 content_page_versions（2026_10_02_000011）之后、V2 基础迁移
 * （2026_10_12_*）之前，是为了不干扰既有 V2MigrationRollbackTest 的
 * --step=4 窗口（该测试断言 V2 四个基础迁移是最后一批）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->text('narrative_role')->nullable()->after('page_type');
            $table->text('new_fact')->nullable()->after('narrative_role');
            $table->text('final_copy')->nullable()->after('new_fact');
            $table->text('visual_evidence')->nullable()->after('final_copy');
            $table->text('continuity')->nullable()->after('visual_evidence');
            $table->text('production_note')->nullable()->after('continuity');
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->dropColumn([
                'narrative_role', 'new_fact', 'final_copy',
                'visual_evidence', 'continuity', 'production_note',
            ]);
        });
    }
};
