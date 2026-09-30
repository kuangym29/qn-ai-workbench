<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('columns', 'content_columns');

        Schema::table('content_columns', function (Blueprint $table): void {
            $table->unique(['project_id', 'id'], 'content_columns_project_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_columns', function (Blueprint $table): void {
            $table->dropUnique('content_columns_project_id_id_unique');
        });

        Schema::rename('content_columns', 'columns');
    }
};
