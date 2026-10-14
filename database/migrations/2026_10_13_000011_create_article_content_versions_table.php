<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B02（2026-10-13 开发）：图文文章版本快照表。
 *
 * 每次正式内容真正发生变化时，写入一条快照（snapshot = 当次完整 article 字段）。
 * 完全 unchanged 不写无意义版本。版本号按 article_content_id 内自增。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_content_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_content_id');
            $table->unsignedBigInteger('version_no');
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['article_content_id', 'version_no'], 'article_versions_unique');
            $table->foreign('article_content_id', 'article_versions_content_foreign')
                ->references('id')->on('article_contents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_content_versions');
    }
};
