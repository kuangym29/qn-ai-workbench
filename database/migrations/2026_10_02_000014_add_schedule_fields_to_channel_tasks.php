<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEV-W05 — ChannelTask 发布排期与实际发布时间。
 *
 * 纯 additive 迁移：只新增两列，不修改 DEV-002 / DEV-004 的历史迁移。
 *
 * - scheduled_at  = 内部发布排期时间（客户端提交，Laravel 归一为 UTC 后存储）
 * - published_at  = 实际发布确认时间（首次进入 published 时写入，缺省用 now()）
 *
 * 两列均可空，且不设默认值，因此所有既有 ChannelTask 行迁移后必然为 null。
 * 数据库统一按 UTC 存储；展示层转换由应用负责。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_tasks', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->after('publish_status');
            $table->timestamp('published_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_tasks', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_at', 'published_at']);
        });
    }
};
