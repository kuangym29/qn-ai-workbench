<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B01（2026-10-13 开发）：小绿书（image_post）每页 8 个正式字段中的 6 个内容字段。
 * page_no / page_type 已存在，这里补齐其余 6 个。
 * 不做任何"万能扩展"字段。
 *
 * 逻辑上置于 content_page_versions（2026_10_02_000011）之后；
 * 回滚测试已改为"持续回滚直到标志列消失"的顺序无关写法，不再依赖 --step=N。
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
