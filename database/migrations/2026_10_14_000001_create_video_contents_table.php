<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 视频号视频制作（video branch）。
 *
 * 视频制作只回答三件事：
 *   1. 整条视频怎么做  → batch_instruction
 *   2. 完整旁白说什么  → narration
 *   3. 每张来源图片怎么动、动多久、备注 → units[]
 *
 * 刻意保持轻量：units 用 JSON 保存「用户可编辑制作信息」，
 * 不复制 final_copy / visual_evidence / 人物 / 场景 / 叙事职责 / 逐段旁白
 * （那些属于来源内容或由 ContentPage 派生的系统信息）。
 *
 * content_branch_id 唯一：一个 video 分支对应一份视频制作。
 *
 * 顺序无关：本迁移不依赖"最后 N 个 migration"，可独立 migrate / rollback / fresh。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_branch_id')->constrained()->cascadeOnDelete();

            $table->text('batch_instruction');   // 整批视频制作说明
            $table->longText('narration');       // 完整旁白稿
            $table->json('units');               // 视频单元：dynamic_instruction / duration_seconds / note

            // 只反映「视频当前依赖的来源页面版本」，不含 hash/版本号等对外语义。
            $table->string('source_fingerprint', 64)->nullable();

            $table->timestamps();

            $table->unique('content_branch_id', 'video_contents_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_contents');
    }
};
