<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5-I03：页面显式引用复用资产的关系表（ContentPage → ReusableAsset）。
 *
 * 这是「这一页明确使用了哪些人物、哪个场景」的唯一显式数据真源。
 * 刻意不复制 project_id / type / name / status / prompt / role 等字段——
 * 它们都已有正式真源（reusable_assets），引用只保存稳定身份 reusable_asset_id。
 *
 * 约束：
 *   content_page_id → content_pages，级联删除（页面没了，引用自然清理）；
 *   reusable_asset_id → reusable_assets，级联删除（Project 整体删除时关系自然清理；
 *                       当前 Reusable Asset 没有公开 DELETE，这条只兜整体删除）。
 *   唯一约束 (content_page_id, reusable_asset_id)：同一页最多绑定同一资产一次。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_page_reusable_asset_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reusable_asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['content_page_id', 'reusable_asset_id'], 'content_page_asset_reference_unique');
            $table->index(['reusable_asset_id'], 'content_page_asset_reference_asset_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_page_reusable_asset_references');
    }
};
