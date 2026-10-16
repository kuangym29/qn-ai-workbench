<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 发布记录：手工"标记已发布"产生的历史事实。
 *
 * 第一版只允许发布 image_post_copy / article_copy / video_production 三类最终渠道成果；
 * image_batch 不是独立发布目标（只是图片文章的制作支持资产）。
 *
 * append-only：发布记录一旦产生不可 UPDATE / DELETE。
 * 唯一约束 (content_branch_id, review_target_type, version_label) 保证同一分支同一目标同一版本
 * 只产生一条发布记录，并作为并发 POST 的兜底安全网（抗并发重复插入）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_branch_id')->constrained()->cascadeOnDelete();

            // 引用当前审核通过的 ReviewRequest（服务器自查，不接受客户端传入）。
            $table->foreignId('review_request_id')->nullable()->constrained('review_requests')->nullOnDelete();

            $table->string('review_target_type'); // ReviewTargetType 稳定内部值
            $table->string('version_label');      // 关联内容版本身份（区别于同分支不同版本）

            // 记录触发发布的 authenticated user；用户删除不影响历史记录。
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // 同一 (content_branch_id + review_target_type + version_label) 只能一条；抗并发重复插入。
            $table->unique(
                ['content_branch_id', 'review_target_type', 'version_label'],
                'publication_records_branch_target_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_records');
    }
};
