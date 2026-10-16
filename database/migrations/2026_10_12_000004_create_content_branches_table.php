<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->string('channel', 40);
            $table->string('format', 40);
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'channel', 'format'], 'content_branches_item_channel_format_unique');
            $table->foreign(['project_id', 'content_item_id'], 'content_branches_project_item_foreign')
                ->references(['project_id', 'id'])->on('content_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_branches');
    }
};
