<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('content_column_id');
            $table->unsignedBigInteger('topic_id');
            $table->string('title', 200);
            $table->string('copy_status', 40)->default('draft');
            $table->timestamps();

            $table->unique(['project_id', 'id'], 'content_items_project_id_id_unique');
            $table->foreign(['project_id', 'content_column_id'], 'items_project_column_foreign')
                ->references(['project_id', 'id'])->on('content_columns')->cascadeOnDelete();
            $table->foreign(['project_id', 'content_column_id', 'topic_id'], 'items_scoped_topic_foreign')
                ->references(['project_id', 'content_column_id', 'id'])->on('topics')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
