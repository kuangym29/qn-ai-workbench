<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageReusableAssetReference;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use App\Services\V2\ContentPageAssetReferenceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P5-I03：content_page_reusable_asset_references 迁移 Gate。
 *
 * 必须：migrate:fresh / rollback / reset / re-migrate 全部通过（顺序无关，不用 --step=N）。
 * 用 DatabaseMigrations：migrate:fresh 会触发 SQLite VACUUM，不能跑在 RefreshDatabase 的事务里。
 */
class ExplicitAssetReferenceMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const TABLE = 'content_page_reusable_asset_references';

    public function test_migration_created_the_reference_table_with_unique_constraint(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE), self::TABLE.' 必须存在');

        foreach (['content_page_id', 'reusable_asset_id'] as $col) {
            $this->assertTrue(Schema::hasColumn(self::TABLE, $col), $col.' 必须存在');
        }
        $this->assertTrue(Schema::hasColumns(self::TABLE, ['created_at', 'updated_at']));

        // 唯一约束 (content_page_id, reusable_asset_id) 真实生效。
        $project = Project::factory()->create();
        $item = ContentItem::factory()->for($project)->create();
        $page = ContentPage::factory()->for($item)->create(['project_id' => $project->id, 'page_no' => 1]);
        $asset = ReusableAsset::query()->create([
            'project_id' => $project->id,
            'type' => ReusableAssetType::Character->value,
            'name' => '妈妈',
            'status' => ReusableAssetStatus::Existing->value,
        ]);

        ContentPageReusableAssetReference::query()->create([
            'content_page_id' => $page->id,
            'reusable_asset_id' => $asset->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ContentPageReusableAssetReference::query()->create([
            'content_page_id' => $page->id,
            'reusable_asset_id' => $asset->id,
        ]);
    }

    public function test_fresh_rebuilds_the_reference_schema(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }

    public function test_reset_drops_the_reference_table(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable(self::TABLE), self::TABLE.' 应已被 reset 清空');
    }

    public function test_rollback_and_remigrate_the_reference_schema(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable(self::TABLE), self::TABLE.' re-migrate 后必须存在');
    }

    public function test_remigrate_after_reset_rebuilds_schema(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }

    public function test_page_delete_cascades_references_and_asset_survives(): void
    {
        $project = Project::factory()->create();
        $item = ContentItem::factory()->for($project)->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'project_id' => $project->id,
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::ImagePost->value,
        ]);
        $page = ContentPage::factory()->for($item)->create(['project_id' => $project->id, 'page_no' => 1]);
        $asset = ReusableAsset::query()->create([
            'project_id' => $project->id,
            'type' => ReusableAssetType::Scene->value,
            'name' => '客厅',
            'status' => ReusableAssetStatus::Existing->value,
        ]);

        /** @var ContentPageAssetReferenceService $service */
        $service = $this->app->make(ContentPageAssetReferenceService::class);
        $service->replace($page, [], $asset->id);
        $this->assertSame(1, ContentPageReusableAssetReference::query()->count());

        $page->delete();

        $this->assertSame(0, ContentPageReusableAssetReference::query()->count(), 'ContentPage 删除 → 引用级联');
        $this->assertDatabaseHas('reusable_assets', ['id' => $asset->id], null, '资产本身不能被引用级联带走');
        unset($branch);
    }

    public function test_asset_delete_cascades_references(): void
    {
        $project = Project::factory()->create();
        $item = ContentItem::factory()->for($project)->create();
        $page = ContentPage::factory()->for($item)->create(['project_id' => $project->id, 'page_no' => 1]);
        $asset = ReusableAsset::query()->create([
            'project_id' => $project->id,
            'type' => ReusableAssetType::Character->value,
            'name' => '妈妈',
            'status' => ReusableAssetStatus::Existing->value,
        ]);

        /** @var ContentPageAssetReferenceService $service */
        $service = $this->app->make(ContentPageAssetReferenceService::class);
        $service->replace($page, [$asset->id], null);
        $this->assertSame(1, ContentPageReusableAssetReference::query()->count());

        // Reusable Asset 当前没有公开 DELETE；这里直接验证 FK 级联语义：
        // 资产被删（例如随 Project 整体清理）时，引用关系必须自然清理，不留下孤儿行。
        $asset->delete();

        $this->assertSame(0, ContentPageReusableAssetReference::query()->count(), '资产删除 → 引用级联');
        $this->assertDatabaseHas('content_pages', ['id' => $page->id], null, '页面本身不能被资产级联带走');
    }
}
