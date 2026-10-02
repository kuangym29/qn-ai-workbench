<?php

namespace Tests\Feature;

use App\Enums\AssetRole;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\File;
use App\Models\ProductionTask;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class SharedVisualAssetCoreTest extends TestCase
{
    use RefreshDatabase;

    private function context(?int $projectId = null): array
    {
        $item = ContentItem::factory()->create($projectId === null ? [] : ['project_id' => $projectId]);
        $page = ContentPage::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $revision = ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $task = ProductionTask::factory()->forCopyRevision($revision)->create();

        return [$item, $page, $revision, $task];
    }

    private function rejectsDatabase(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected database constraint rejection.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    private function assetRow(ContentItem $item, ContentPage $page, ProductionTask $task, string $role = 'copy_master'): array
    {
        return [
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'production_task_id' => $task->id,
            'content_page_id' => $page->id,
            'role' => $role,
        ];
    }

    private function versionRow(Asset $asset, ContentCopyRevision $revision, File $file, int $number = 2): array
    {
        return [
            'project_id' => $asset->project_id,
            'content_item_id' => $asset->content_item_id,
            'asset_id' => $asset->id,
            'copy_revision_id' => $revision->id,
            'file_id' => $file->id,
            'version_no' => $number,
        ];
    }

    public function test_default_factories_build_a_scoped_asset_version(): void
    {
        $version = AssetVersion::factory()->create();
        $this->assertSame($version->project_id, $version->asset->project_id);
        $this->assertSame($version->content_item_id, $version->asset->content_item_id);
        $this->assertSame($version->project_id, $version->copyRevision->project_id);
        $this->assertSame($version->content_item_id, $version->copyRevision->content_item_id);
        $this->assertSame($version->project_id, $version->file->project_id);
        $this->assertSame($version->asset->production_task_id, $version->asset->contentItem->productionTask->id);
    }

    public function test_two_roles_share_one_page_but_same_role_cannot_repeat(): void
    {
        [$item, $page, $revision, $task] = $this->context();
        $clean = Asset::factory()->forProductionPage($task, $page)->cleanMaster()->create();
        $copy = Asset::factory()->forProductionPage($task, $page)->copyMaster()->create();
        $this->assertSame(AssetRole::CleanMaster, $clean->fresh()->role);
        $this->assertSame(AssetRole::CopyMaster, $copy->fresh()->role);
        $this->assertSame(2, $task->assets()->count());
        $this->assertSame(2, $page->assets()->count());
        $this->assertSame(2, $item->assets()->count());
        $this->assertSame(2, $item->project->assets()->count());
        $this->rejectsDatabase(fn () => DB::table('assets')->insert($this->assetRow($item, $page, $task, 'clean_master')));
        $this->assertDatabaseCount('assets', 2);
        $this->assertSame($revision->id, $task->copy_revision_id);
    }

    public function test_asset_scope_rejects_wrong_production_and_page(): void
    {
        [$item, $page, , $task] = $this->context();
        [, $otherPage, , $otherTask] = $this->context($item->project_id);
        [, $foreignPage, , $foreignTask] = $this->context();

        foreach ([
            $this->assetRow($item, $page, $foreignTask),
            $this->assetRow($item, $page, $otherTask),
            $this->assetRow($item, $otherPage, $task),
            $this->assetRow($item, $foreignPage, $task),
        ] as $row) {
            $this->rejectsDatabase(fn () => DB::table('assets')->insert($row));
        }
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_version_scope_rejects_wrong_asset_revision_and_file(): void
    {
        [$item, $page, $revision, $task] = $this->context();
        $asset = Asset::factory()->forProductionPage($task, $page)->create();
        $file = File::factory()->create(['project_id' => $item->project_id]);
        AssetVersion::factory()->forAssetAndCopyRevision($asset, $revision)->create(['file_id' => $file->id]);

        [$otherItem, $otherPage, $otherRevision, $otherTask] = $this->context($item->project_id);
        $otherAsset = Asset::factory()->forProductionPage($otherTask, $otherPage)->create();
        [$foreignItem, $foreignPage, $foreignRevision, $foreignTask] = $this->context();
        $foreignAsset = Asset::factory()->forProductionPage($foreignTask, $foreignPage)->create();
        $foreignFile = File::factory()->create(['project_id' => $foreignItem->project_id]);

        foreach ([
            [...$this->versionRow($asset, $revision, $file), 'asset_id' => $foreignAsset->id],
            [...$this->versionRow($asset, $revision, $file), 'asset_id' => $otherAsset->id],
            [...$this->versionRow($asset, $revision, $file), 'copy_revision_id' => $foreignRevision->id],
            [...$this->versionRow($asset, $revision, $file), 'copy_revision_id' => $otherRevision->id],
            [...$this->versionRow($asset, $revision, $file), 'file_id' => $foreignFile->id],
        ] as $row) {
            $this->rejectsDatabase(fn () => DB::table('asset_versions')->insert($row));
        }
        $this->assertSame(1, $item->assetVersions()->count());
        $this->assertSame(1, $revision->assetVersions()->count());
        $this->assertSame(1, $file->assetVersions()->count());
        $this->assertSame($otherItem->project_id, $item->project_id);
    }

    public function test_file_location_uniqueness_is_project_scoped(): void
    {
        $first = File::factory()->create(['storage_disk' => 'local', 'storage_path' => 'visual/page-1.png']);
        $this->rejectsDatabase(fn () => File::factory()->create([
            'project_id' => $first->project_id, 'storage_disk' => 'local', 'storage_path' => 'visual/page-1.png',
        ]));
        $other = File::factory()->create(['storage_disk' => 'local', 'storage_path' => 'visual/page-1.png']);
        $this->assertNotSame($first->project_id, $other->project_id);
        $this->assertSame(1, $first->project->files()->count());
    }

    public function test_versions_append_and_preserve_revision_one_after_production_rebind(): void
    {
        [$item, $page, $revisionOne, $task] = $this->context();
        $asset = Asset::factory()->forProductionPage($task, $page)->cleanMaster()->create();
        $versionOne = AssetVersion::factory()->forAssetAndCopyRevision($asset, $revisionOne)->create();
        $this->assertSame(1, $versionOne->version_no);
        $this->assertSame($revisionOne->id, $versionOne->copy_revision_id);
        $revisionTwo = ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 2,
        ]);
        $task->forceFill(['copy_revision_id' => $revisionTwo->id])->save();
        $versionTwo = AssetVersion::factory()->forAssetAndCopyRevision($asset, $revisionTwo)->create(['version_no' => 2]);
        $versionThree = AssetVersion::factory()->forAssetAndCopyRevision($asset, $revisionOne)->create(['version_no' => 3]);

        $this->assertSame($revisionOne->id, $versionOne->fresh()->copy_revision_id);
        $this->assertSame($revisionTwo->id, $versionTwo->fresh()->copy_revision_id);
        $this->assertSame($revisionOne->id, $versionThree->fresh()->copy_revision_id);
        $this->assertSame($versionTwo->id, $asset->versions()->where('copy_revision_id', $task->fresh()->copy_revision_id)
            ->orderByDesc('version_no')->firstOrFail()->id);
        $this->assertSame($versionThree->id, $asset->versions()->orderByDesc('version_no')->firstOrFail()->id);
        $this->rejectsDatabase(fn () => DB::table('asset_versions')->insert($this->versionRow(
            $asset, $revisionTwo, File::factory()->create(['project_id' => $item->project_id]), 2
        )));
    }

    public function test_asset_identity_and_version_rows_are_immutable(): void
    {
        [$item, $page, $revision, $task] = $this->context();
        $asset = Asset::factory()->forProductionPage($task, $page)->create();
        $version = AssetVersion::factory()->forAssetAndCopyRevision($asset, $revision)->create();

        try {
            $asset->forceFill(['role' => AssetRole::CopyMaster])->save();
            $this->fail('Expected Asset identity guard.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            $version->update(['note' => 'changed']);
            $this->fail('Expected AssetVersion update guard.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            $version->delete();
            $this->fail('Expected AssetVersion delete guard.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertSame(AssetRole::CleanMaster, $asset->fresh()->role);
        $this->assertNull($version->fresh()->note);
        $this->assertDatabaseCount('asset_versions', 1);
        $this->assertSame($item->project_id, $version->project_id);
    }

    public function test_referenced_file_revision_asset_and_project_deletion_are_restricted(): void
    {
        [$item, $page, $revision, $task] = $this->context();
        $asset = Asset::factory()->forProductionPage($task, $page)->create();
        $version = AssetVersion::factory()->forAssetAndCopyRevision($asset, $revision)->create();
        foreach ([
            fn () => DB::table('files')->where('id', $version->file_id)->delete(),
            fn () => DB::table('content_copy_revisions')->where('id', $revision->id)->delete(),
            fn () => DB::table('assets')->where('id', $asset->id)->delete(),
            fn () => DB::table('projects')->where('id', $item->project_id)->delete(),
        ] as $action) {
            $this->rejectsDatabase($action);
        }
        $this->assertDatabaseCount('asset_versions', 1);
        $this->assertDatabaseHas('files', ['id' => $version->file_id]);
    }
}
