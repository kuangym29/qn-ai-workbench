<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 图片批次生产 —— 批次执行记录（轻量历史）。
 *
 * 第一阶段只创建「批次执行记录」，不调用任何生图模型 / ChatGPT Work：
 * run 创建后状态为 queued，绝不伪造成 completed / generated。
 *
 * instruction_snapshot 保存当次的：
 *   整批说明 / 整批备注 / 相关页面当前内容摘要 / 特殊要求 / 资产摘要
 * 目的是未来可追溯，不存重复 SOP 全文。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_batch_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('image_batch_draft_id')
                ->constrained('image_batch_drafts')->cascadeOnDelete();

            $table->string('mode', 20);                 // full / trial / partial
            $table->json('unit_ids');                   // 本次参与的页面单元 id
            $table->string('status', 20);               // queued / completed / failed
            $table->json('instruction_snapshot');       // 当次任务快照
            $table->timestamp('created_at')->nullable();

            $table->index(['image_batch_draft_id', 'created_at'], 'image_batch_runs_draft_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_batch_runs');
    }
};
