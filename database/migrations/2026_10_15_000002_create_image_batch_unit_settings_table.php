<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 图片批次生产 —— 单页生产设置。
 *
 * 只保存「用户对这一页的补充 + 本页生产状态 + 来源 fingerprint」：
 *   special_requirement 本页特殊要求（用户可编辑）
 *   source_fingerprint  来源页面内容指纹（内部判断用，不对外暴露）
 *   status              单页生产状态（pending / generated / needs_revision / approved）
 *
 * 不复制 final_copy / visual_evidence / characters / scene / page_type —— 那些一律实时读 ContentPage。
 *
 * content_page_id 在 draft 内唯一。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_batch_unit_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('image_batch_draft_id')
                ->constrained('image_batch_drafts')->cascadeOnDelete();
            $table->foreignId('content_page_id')->constrained()->cascadeOnDelete();

            $table->text('special_requirement')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('status', 20)->default('pending');

            $table->timestamps();

            $table->unique(
                ['image_batch_draft_id', 'content_page_id'],
                'image_batch_unit_settings_draft_page_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_batch_unit_settings');
    }
};
