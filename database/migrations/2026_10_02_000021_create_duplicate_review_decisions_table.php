<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_page_versions', function (Blueprint $table): void {
            $table->unique(['project_id', 'content_item_id', 'id'], 'page_versions_decision_query_scope_unique');
            $table->unique(['project_id', 'id'], 'page_versions_decision_match_scope_unique');
        });

        Schema::create('duplicate_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('query_page_version_id');
            $table->string('query_field', 40);
            $table->unsignedBigInteger('match_page_version_id');
            $table->string('match_field', 40);
            $table->unsignedBigInteger('decision_no');
            $table->string('decision', 40);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['query_page_version_id', 'query_field', 'match_page_version_id', 'match_field', 'decision_no'],
                'duplicate_review_pair_decision_no_unique'
            );
            $table->index(['project_id', 'content_item_id', 'query_page_version_id'], 'duplicate_review_query_index');
            $table->foreign(['project_id', 'content_item_id'], 'duplicate_review_item_foreign')
                ->references(['project_id', 'id'])->on('content_items')->restrictOnDelete();
            $table->foreign(['project_id', 'content_item_id', 'query_page_version_id'], 'duplicate_review_query_foreign')
                ->references(['project_id', 'content_item_id', 'id'])->on('content_page_versions')->restrictOnDelete();
            $table->foreign(['project_id', 'match_page_version_id'], 'duplicate_review_match_foreign')
                ->references(['project_id', 'id'])->on('content_page_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_review_decisions');
        Schema::table('content_page_versions', function (Blueprint $table): void {
            $table->dropUnique('page_versions_decision_query_scope_unique');
            $table->dropUnique('page_versions_decision_match_scope_unique');
        });
    }
};
