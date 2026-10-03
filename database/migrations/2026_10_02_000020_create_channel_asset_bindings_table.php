<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_asset_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('production_task_id');
            $table->unsignedBigInteger('channel_task_id');
            $table->unsignedBigInteger('content_page_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('asset_version_id');
            $table->unsignedBigInteger('copy_revision_id');
            $table->unsignedBigInteger('binding_no');
            $table->timestamps();

            $table->unique(['channel_task_id', 'content_page_id', 'binding_no'], 'channel_asset_bindings_page_no_unique');
            $table->foreign(['project_id', 'production_task_id', 'channel_task_id'], 'channel_asset_bindings_scoped_channel_fk')
                ->references(['project_id', 'production_task_id', 'id'])->on('channel_tasks')->restrictOnDelete();
            $table->foreign(['project_id', 'content_item_id', 'production_task_id', 'content_page_id', 'asset_id'], 'channel_asset_bindings_scoped_asset_fk')
                ->references(['project_id', 'content_item_id', 'production_task_id', 'content_page_id', 'id'])->on('assets')->restrictOnDelete();
            $table->foreign(['project_id', 'content_item_id', 'asset_id', 'copy_revision_id', 'asset_version_id'], 'channel_asset_bindings_scoped_version_fk')
                ->references(['project_id', 'content_item_id', 'asset_id', 'copy_revision_id', 'id'])->on('asset_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_asset_bindings');
    }
};
