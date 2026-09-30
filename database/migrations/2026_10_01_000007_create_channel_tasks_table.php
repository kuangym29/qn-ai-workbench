<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('production_task_id');
            $table->string('channel', 60);
            $table->string('video_status', 40)->nullable();
            $table->string('publish_status', 40)->default('not_published');
            $table->timestamps();

            $table->unique(['production_task_id', 'channel']);
            $table->foreign(['project_id', 'production_task_id'], 'channel_project_production_foreign')
                ->references(['project_id', 'id'])->on('production_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_tasks');
    }
};
