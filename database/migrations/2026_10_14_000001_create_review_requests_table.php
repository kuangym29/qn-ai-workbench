<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 轻量审核中心：审核请求。
 *
 * 审核中心不理解的各模块内部结构——只保存：
 *   审核目标(review_target_type/label)、关联内容/分支、关联版本(version_label)、
 *   可读摘要(preview_summary)、可读预览(preview JSON)、审核状态(status)、决策时间。
 *
 * 顺序无关：本迁移不依赖"最后 N 个 migration"，可独立 migrate / rollback / fresh。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_branch_id')->constrained()->cascadeOnDelete();

            $table->string('review_target_type');   // ReviewTargetType 值（稳定内部值）
            $table->string('review_target_label');  // 展示字段（后端提供，不让前端猜）
            $table->string('version_label');        // 关联版本标签（区分同分支不同版本）

            $table->text('preview_summary')->nullable(); // 可读摘要
            $table->json('preview')->nullable();    // 轻量可读预览：{kind: text|lines|items, ...}（允许为空，缺省按 text 透传）

            $table->string('status');              // ReviewStatus 值（当前状态，便于筛 pending）
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            // 重复 pending 防护查询索引（不建唯一约束：新版本允许产生新请求）。
            $table->index(
                ['content_branch_id', 'review_target_type', 'version_label', 'status'],
                'review_requests_pending_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_requests');
    }
};
