<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->unsignedBigInteger('inspiration_id')->nullable()->after('content_column_id');
            $table->string('status', 40)->default('pending')->after('description');
            $table->boolean('is_backup')->default(false)->after('status');

            $table->foreign(['project_id', 'inspiration_id'], 'topics_project_inspiration_foreign')
                ->references(['project_id', 'id'])->on('inspirations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropForeign('topics_project_inspiration_foreign');
            $table->dropColumn(['inspiration_id', 'status', 'is_backup']);
        });
    }
};
