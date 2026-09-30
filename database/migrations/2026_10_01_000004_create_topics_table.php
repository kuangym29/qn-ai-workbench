<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('content_column_id');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'content_column_id', 'id'], 'topics_scope_column_id_unique');
            $table->foreign(['project_id', 'content_column_id'], 'topics_project_column_foreign')
                ->references(['project_id', 'id'])->on('content_columns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
