<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->unique(['project_id', 'content_item_id', 'id'], 'production_tasks_asset_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->dropUnique('production_tasks_asset_scope_unique');
        });
    }
};
