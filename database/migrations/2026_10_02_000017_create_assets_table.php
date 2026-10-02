<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('production_task_id');
            $table->unsignedBigInteger('content_page_id');
            $table->string('role', 40);
            $table->timestamps();

            $table->unique(['production_task_id', 'content_page_id', 'role'], 'assets_task_page_role_unique');
            $table->unique(['project_id', 'content_item_id', 'id'], 'assets_scope_id_unique');
            $table->foreign(['project_id', 'content_item_id', 'production_task_id'], 'assets_scoped_production_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('production_tasks')->restrictOnDelete();
            $table->foreign(['project_id', 'content_item_id', 'content_page_id'], 'assets_scoped_page_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('content_pages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
