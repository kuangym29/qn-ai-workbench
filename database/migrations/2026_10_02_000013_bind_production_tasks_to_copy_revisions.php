<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('copy_revision_id')->nullable()->after('content_item_id');
            $table->foreign(['project_id', 'content_item_id', 'copy_revision_id'], 'production_scoped_copy_revision_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('content_copy_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            Schema::disableForeignKeyConstraints();
        }

        try {
            Schema::table('production_tasks', function (Blueprint $table) use ($sqlite): void {
                $table->dropForeign($sqlite
                    ? ['project_id', 'content_item_id', 'copy_revision_id']
                    : 'production_scoped_copy_revision_foreign');
                $table->dropColumn('copy_revision_id');
            });
        } finally {
            if ($sqlite) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
};
