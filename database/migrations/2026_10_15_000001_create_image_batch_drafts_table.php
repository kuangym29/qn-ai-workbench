<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 图片批次生产 —— 整批草稿。
 *
 * ImageBatch 是隐藏的生产抽象：用户只看到
 *   整批生图说明（batch_instruction）
 *   整批备注（batch_note）
 *   页面单元 + 资产检查摘要 + 整批 / 试样 / 部分生成 + 历史批次
 *
 * 刻意保持轻量：
 *   - 只保存用户对「这一批怎么做」的补充，不复制页面原文；
 *   - 页面内容一律从 ContentPage 实时读取；
 *   - 不建 Prompt 数据库 / 任务管理系统 / 工作流引擎。
 *
 * content_branch_id 唯一：一个分支一份图片批次草稿。
 *
 * 顺序无关：不依赖"最后 N 个 migration"，可独立 migrate / rollback / fresh。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_batch_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_branch_id')->constrained()->cascadeOnDelete();

            $table->text('batch_instruction');          // 整批生图说明
            $table->text('batch_note')->nullable();     // 整批备注（弱化）

            $table->timestamps();

            $table->unique('content_branch_id', 'image_batch_drafts_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_batch_drafts');
    }
};
