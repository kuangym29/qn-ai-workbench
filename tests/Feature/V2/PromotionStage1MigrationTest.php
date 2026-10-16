<?php

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionStage1MigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const TABLES = ['article_contents', 'article_content_versions', 'video_contents', 'video_content_versions', 'image_batch_drafts', 'image_batch_unit_settings', 'image_batch_runs'];

    public function test_all_three_schemas_survive_fresh_reset_rollback_and_remigrate(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        foreach (self::TABLES as $table) $this->assertTrue(Schema::hasTable($table));
        foreach (['migrate:reset', 'migrate:rollback'] as $operation) {
            $this->artisan($operation, ['--force' => true])->assertExitCode(0);
            foreach (self::TABLES as $table) $this->assertFalse(Schema::hasTable($table));
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            foreach (self::TABLES as $table) $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_three_business_routes_have_no_duplicate_method_uri(): void
    {
        $keys = [];
        foreach (Route::getRoutes() as $route) {
            if (! preg_match('~/(article|video|image-batch)(/|$)~', $route->uri())) continue;
            foreach ($route->methods() as $method) $keys[] = $method.' '.$route->uri();
        }
        $this->assertCount(count(array_unique($keys)), $keys);
        foreach (['article', 'video', 'image-batch'] as $suffix) {
            $this->assertContains('GET projects/{project}/content/{item}/branches/{branch}/'.$suffix, $keys);
            $this->assertContains('PUT api/projects/{project}/items/{item}/branches/{branch}/'.$suffix, $keys);
        }
    }
}
