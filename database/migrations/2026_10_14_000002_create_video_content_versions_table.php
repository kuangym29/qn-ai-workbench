<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 视频制作版本快照（append-only）。
 *
 * - version_no 同一 video_content 从 1 递增。
 * - snapshot 只保存正式视频制作字段（batch_instruction / narration / units）。
 * - 人工 PUT 真正变化才写版本；完全 unchanged 不写。
 *
 * 顺序无关：可独立 migrate / rollback / fresh。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_content_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_content_id')->constrained('video_contents')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('snapshot');

            // 仅 created_at：版本记录不可变，不更新。
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_content_versions');
    }
};
