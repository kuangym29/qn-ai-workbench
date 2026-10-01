<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_items')->where('copy_status', 'draft')->update(['copy_status' => 'not_started']);
        DB::table('channel_tasks')->where('publish_status', 'not_published')->update(['publish_status' => 'unpublished']);
        DB::table('channel_tasks')->whereNull('video_status')->update(['video_status' => 'not_applicable']);

        $this->changeDefaults(false);
    }

    public function down(): void
    {
        $this->changeDefaults(true);
    }

    private function changeDefaults(bool $legacy): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            Schema::disableForeignKeyConstraints();
        }

        try {
            Schema::table('content_items', function (Blueprint $table) use ($legacy): void {
                $table->string('copy_status', 40)->default($legacy ? 'draft' : 'not_started')->change();
            });

            Schema::table('channel_tasks', function (Blueprint $table) use ($legacy): void {
                $table->string('video_status', 40)
                    ->nullable($legacy)
                    ->default($legacy ? null : 'not_applicable')
                    ->change();
                $table->string('publish_status', 40)->default($legacy ? 'not_published' : 'unpublished')->change();
            });
        } finally {
            if ($sqlite) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
};
