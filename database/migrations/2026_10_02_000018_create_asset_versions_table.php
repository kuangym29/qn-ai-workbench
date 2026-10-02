<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('copy_revision_id');
            $table->unsignedBigInteger('file_id');
            $table->unsignedBigInteger('version_no');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'version_no'], 'asset_versions_asset_no_unique');
            $table->foreign(['project_id', 'content_item_id', 'asset_id'], 'asset_versions_scoped_asset_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('assets')->restrictOnDelete();
            $table->foreign(['project_id', 'content_item_id', 'copy_revision_id'], 'asset_versions_scoped_revision_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('content_copy_revisions')->restrictOnDelete();
            $table->foreign(['project_id', 'file_id'], 'asset_versions_scoped_file_foreign')
                ->references(['project_id', 'id'])->on('files')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_versions');
    }
};
