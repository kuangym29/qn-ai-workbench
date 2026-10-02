<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('storage_disk', 80);
            $table->string('storage_path', 640);
            $table->string('original_name', 255);
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'id'], 'files_project_id_id_unique');
            $table->unique(['project_id', 'storage_disk', 'storage_path'], 'files_storage_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
