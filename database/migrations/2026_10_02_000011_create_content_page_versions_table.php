<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('content_page_id');
            $table->unsignedBigInteger('copy_revision_id')->nullable();
            $table->unsignedBigInteger('version_no');
            $table->unsignedBigInteger('page_no_snapshot')->nullable();
            $table->string('page_type_snapshot', 40)->nullable();
            $table->text('column_label')->nullable();
            $table->text('cover_title')->nullable();
            $table->text('cover_subtitle')->nullable();
            $table->text('page_title')->nullable();
            $table->text('page_small_text')->nullable();
            $table->text('closing_line')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['content_page_id', 'version_no'], 'page_versions_page_no_unique');
            $table->unique(['copy_revision_id', 'content_page_id'], 'page_versions_revision_page_unique');
            $table->foreign(['project_id', 'content_item_id', 'content_page_id'], 'page_versions_scoped_page_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('content_pages')->restrictOnDelete();
            $table->foreign(['project_id', 'content_item_id', 'copy_revision_id'], 'page_versions_scoped_revision_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('content_copy_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_page_versions');
    }
};
