<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B02（2026-10-13 开发）：公众号 · 图文文章（article）正式内容表。
 *
 * 图文文章不是逐页内容，因此不使用 ContentPage / page_no / PageType / 固定封底。
 * 正式字段仅：headline / summary / body / image_suggestions / cover_suggestion。
 * 共享字段（title / core_message / story_line）继续来自 ContentItem，不在此重复。
 * image_suggestions 直接用 JSON 存储（结构见产品规则），不另造复杂配图表。
 *
 * content_branch_id 唯一：一个 Branch 只有一份图文文章正式内容。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('content_branch_id');
            $table->text('headline')->nullable();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->json('image_suggestions')->nullable();
            $table->text('cover_suggestion')->nullable();
            $table->timestamps();

            $table->unique('content_branch_id', 'article_contents_branch_unique');
            $table->foreign('content_item_id', 'article_contents_item_foreign')
                ->references('id')->on('content_items')->restrictOnDelete();
            $table->foreign('content_branch_id', 'article_contents_branch_foreign')
                ->references('id')->on('content_branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_contents');
    }
};
