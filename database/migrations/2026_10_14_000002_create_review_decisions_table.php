<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 轻量审核中心：审核决策记录。
 *
 * append-only：决策只新增、不更新、不删除。
 * decision_no 同一 review_request 从 1 开始递增（第一阶段正常只有一次决策，
 * 但结构保持 append-only，便于未来扩展）。
 *
 * 顺序无关：可独立 migrate / rollback / fresh。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_request_id')->constrained('review_requests')->cascadeOnDelete();
            $table->unsignedInteger('decision_no');
            $table->string('decision');             // ReviewDecision 值
            $table->text('comment')->nullable();    // changes_requested 时必填
            $table->string('target_label')->nullable(); // 轻量局部定位，如 P4 / 正文 / 封面建议

            // 仅 created_at：决策记录不可变，不更新。
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_decisions');
    }
};
