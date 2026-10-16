<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->text('core_message')->nullable()->after('title');
            $table->text('story_line')->nullable()->after('core_message');
            $table->unique('topic_id', 'content_items_topic_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropUnique('content_items_topic_id_unique');
            $table->dropColumn(['core_message', 'story_line']);
        });
    }
};
