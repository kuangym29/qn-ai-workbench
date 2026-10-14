<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 复用资产索引（单表，不区分 character / scene 两套表）。
 *
 * 只登记「有没有 / 缺不缺」这一个问题，刻意不保存：
 * prompt / model / seed / workflow / owner / folder / approval / technical metadata。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reusable_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 200);
            $table->string('status', 20);
            $table->string('thumbnail_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'type', 'name'], 'reusable_assets_project_type_name_unique');
            $table->index(['project_id', 'type'], 'reusable_assets_project_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reusable_assets');
    }
};
