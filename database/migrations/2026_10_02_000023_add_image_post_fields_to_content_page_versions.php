<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B01（2026-10-13 开发）：content_page_versions 快照同步补齐 6 个内容字段，
 * 使每次 apply 后保留的版本快照包含完整的页面内容（版本保留）。
 *
 * 时间戳置于 content_page_versions（2026_10_02_000011）之后、V2 基础迁移
 * （2026_10_12_*）之前，不干扰既有 V2MigrationRollbackTest 的 --step=4 窗口。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_page_versions', function (Blueprint $table): void {
            $table->text('narrative_role')->nullable()->after('page_type_snapshot');
            $table->text('new_fact')->nullable()->after('narrative_role');
            $table->text('final_copy')->nullable()->after('new_fact');
            $table->text('visual_evidence')->nullable()->after('final_copy');
            $table->text('continuity')->nullable()->after('visual_evidence');
            $table->text('production_note')->nullable()->after('continuity');
        });
    }

    public function down(): void
    {
        Schema::table('content_page_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'narrative_role', 'new_fact', 'final_copy',
                'visual_evidence', 'continuity', 'production_note',
            ]);
        });
    }
};
