<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_copy_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('revision_no');
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['content_item_id', 'revision_no'], 'copy_revisions_item_no_unique');
            $table->unique(['project_id', 'content_item_id', 'id'], 'copy_revisions_scope_id_unique');
            $table->foreign(['project_id', 'content_item_id'], 'copy_revisions_scoped_item_foreign')
                ->references(['project_id', 'id'])->on('content_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_copy_revisions');
    }
};
