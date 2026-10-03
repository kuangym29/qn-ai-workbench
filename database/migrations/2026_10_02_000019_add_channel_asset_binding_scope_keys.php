<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_tasks', function (Blueprint $table): void {
            $table->unique(['project_id', 'production_task_id', 'id'], 'channel_tasks_binding_scope_unique');
        });
        Schema::table('assets', function (Blueprint $table): void {
            $table->unique(['project_id', 'content_item_id', 'production_task_id', 'content_page_id', 'id'], 'assets_binding_scope_unique');
        });
        Schema::table('asset_versions', function (Blueprint $table): void {
            $table->unique(['project_id', 'content_item_id', 'asset_id', 'copy_revision_id', 'id'], 'asset_versions_binding_scope_unique');
        });

        // MySQL may use the scoped unique keys as supporting indexes for older foreign keys.
        // Remove the temporary indexes left by a previous rollback only after the unique keys exist.
        if (Schema::hasIndex('asset_versions', 'asset_versions_binding_rollback_asset_idx')) {
            Schema::table('asset_versions', fn (Blueprint $table) => $table->dropIndex('asset_versions_binding_rollback_asset_idx'));
        }
        if (Schema::hasIndex('assets', 'assets_binding_rollback_production_idx')) {
            Schema::table('assets', fn (Blueprint $table) => $table->dropIndex('assets_binding_rollback_production_idx'));
        }
        if (Schema::hasIndex('channel_tasks', 'channel_tasks_binding_rollback_production_idx')) {
            Schema::table('channel_tasks', fn (Blueprint $table) => $table->dropIndex('channel_tasks_binding_rollback_production_idx'));
        }
    }

    public function down(): void
    {
        // Restore FK supporting indexes before removing unique keys used by MySQL.
        if (! Schema::hasIndex('asset_versions', 'asset_versions_binding_rollback_asset_idx')) {
            Schema::table('asset_versions', fn (Blueprint $table) => $table->index(['project_id', 'content_item_id', 'asset_id'], 'asset_versions_binding_rollback_asset_idx'));
        }
        if (! Schema::hasIndex('assets', 'assets_binding_rollback_production_idx')) {
            Schema::table('assets', fn (Blueprint $table) => $table->index(['project_id', 'content_item_id', 'production_task_id'], 'assets_binding_rollback_production_idx'));
        }
        if (! Schema::hasIndex('channel_tasks', 'channel_tasks_binding_rollback_production_idx')) {
            Schema::table('channel_tasks', fn (Blueprint $table) => $table->index(['project_id', 'production_task_id'], 'channel_tasks_binding_rollback_production_idx'));
        }

        Schema::table('asset_versions', fn (Blueprint $table) => $table->dropUnique('asset_versions_binding_scope_unique'));
        Schema::table('assets', fn (Blueprint $table) => $table->dropUnique('assets_binding_scope_unique'));
        Schema::table('channel_tasks', fn (Blueprint $table) => $table->dropUnique('channel_tasks_binding_scope_unique'));
    }
};
