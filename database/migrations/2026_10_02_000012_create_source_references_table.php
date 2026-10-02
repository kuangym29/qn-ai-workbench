<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('content_item_id')->nullable();
            $table->string('role', 40);
            $table->string('authority', 40);
            $table->string('source_path', 1000);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign(['project_id', 'content_item_id'], 'source_references_scoped_item_foreign')
                ->references(['project_id', 'id'])->on('content_items')->cascadeOnDelete();
            $table->index(['project_id', 'role'], 'source_references_project_role_index');
            $table->index(['project_id', 'content_item_id', 'role'], 'source_references_project_item_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_references');
    }
};
