<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspirations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'id'], 'inspirations_project_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspirations');
    }
};
