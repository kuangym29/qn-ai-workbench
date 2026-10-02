<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('page_no');
            $table->string('page_type', 40);
            $table->timestamps();

            $table->unique(['content_item_id', 'page_no'], 'content_pages_item_page_no_unique');
            $table->unique(['project_id', 'content_item_id', 'id'], 'content_pages_scope_id_unique');
            $table->foreign(['project_id', 'content_item_id'], 'content_pages_scoped_item_foreign')
                ->references(['project_id', 'id'])->on('content_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
