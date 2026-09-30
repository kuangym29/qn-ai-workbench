<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->string('artwork_status', 40)->default('not_started');
            $table->timestamps();

            $table->unique('content_item_id');
            $table->unique(['project_id', 'id'], 'production_tasks_project_id_id_unique');
            $table->foreign(['project_id', 'content_item_id'], 'production_project_item_foreign')
                ->references(['project_id', 'id'])->on('content_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_tasks');
    }
};
