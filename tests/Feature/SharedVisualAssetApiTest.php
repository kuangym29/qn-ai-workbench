<?php

namespace Tests\Feature;

use App\Enums\ArtworkStatus;
use App\Models\Asset;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedVisualAssetApiTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $withTask = true): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $url = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/production/assets";
        $task = $withTask ? ProductionTask::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $item->id,
        ]) : null;

        return [$project, $item, $task, $url];
    }

    private function formalPage(ContentItem $item, ContentCopyRevision $revision, int $pageNo = 1, ?ContentPage $page = null): ContentPage
    {
        $page ??= ContentPage::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'page_no' => $pageNo,
        ]);
        ContentPageVersion::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'content_page_id' => $page->id, 'copy_revision_id' => $revision->id,
            'version_no' => $page->versions()->count() + 1,
            'page_no_snapshot' => $pageNo, 'page_type_snapshot' => 'cover',
        ]);

        return $page;
    }

    private function pinned(ContentItem $item, ProductionTask $task, int $number = 1): ContentCopyRevision
    {
        $revision = ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => $number,
        ]);
        $task->forceFill(['copy_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function payload(ContentPage $page, string $path = 'masters/page-1.png', string $role = 'clean_master'): array
    {
        return [
            'content_page_id' => $page->id, 'role' => $role,
            'storage_disk' => 'local', 'storage_path' => $path,
            'original_name' => 'page-1.png', 'mime_type' => 'image/png',
            'size_bytes' => 100, 'width' => 100, 'height' => 200,
        ];
    }

    public function test_missing_and_legacy_production_are_safe(): void
    {
        [, $item, , $url] = $this->context(false);
        $page = ContentPage::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $this->getJson($url)->assertNotFound();
        $this->postJson("$url/versions", $this->payload($page))->assertNotFound();
        ProductionTask::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.copy_revision_id', null)
            ->assertJsonPath('data.copy_revision_no', null)->assertJsonPath('data.is_copy_revision_current', false)
            ->assertJsonCount(0, 'data.pages');
        $this->postJson("$url/versions", $this->payload($page))->assertUnprocessable()->assertJsonValidationErrors('production');
    }

    public function test_workspace_uses_pinned_snapshot_and_page_gate(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->pinned($item, $task);
        $page = $this->formalPage($item, $revision, 1);
        $page->update(['page_no' => 3, 'page_type' => 'content']);
        $newPage = ContentPage::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'page_no' => 2]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.pages')
            ->assertJsonPath('data.pages.0.content_page_id', $page->id)
            ->assertJsonPath('data.pages.0.page_no', 1)
            ->assertJsonPath('data.pages.0.page_type', 'cover')
            ->assertJsonPath('data.pages.0.assets.clean_master', null);
        $this->postJson("$url/versions", $this->payload($newPage))->assertUnprocessable()->assertJsonValidationErrors('content_page_id');
        $other = ContentItem::factory()->create(['project_id' => $item->project_id]);
        $foreignPage = ContentPage::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $other->id]);
        $this->postJson("$url/versions", $this->payload($foreignPage))->assertNotFound();
        $cross = ContentPage::factory()->create();
        $this->postJson("$url/versions", $this->payload($cross))->assertNotFound();
    }

    public function test_append_reuses_slot_and_file_and_tracks_revision_history(): void
    {
        [$project, $item, $task, $url] = $this->context();
        $revisionOne = $this->pinned($item, $task);
        $page = $this->formalPage($item, $revisionOne);
        $first = $this->postJson("$url/versions", $this->payload($page))->assertCreated()
            ->assertJsonPath('data.version_no', 1)->assertJsonPath('data.copy_revision_no', 1)
            ->assertJsonPath('data.file.project_id', $project->id);
        $this->postJson("$url/versions", $this->payload($page))->assertCreated()
            ->assertJsonPath('data.version_no', 2)->assertJsonPath('data.file.id', $first->json('data.file.id'));
        $this->postJson("$url/versions", $this->payload($page, 'masters/copy.png', 'copy_master'))->assertCreated();
        $this->assertDatabaseCount('assets', 2);
        $this->assertDatabaseCount('files', 2);
        $this->assertDatabaseCount('asset_versions', 3);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.pages.0.assets.clean_master.version_count', 2)
            ->assertJsonPath('data.pages.0.assets.clean_master.current_version.version_no', 2)
            ->assertJsonPath('data.pages.0.assets.copy_master.role', 'copy_master');

        $revisionTwo = ContentCopyRevision::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $item->id, 'revision_no' => 2,
        ]);
        $this->formalPage($item, $revisionTwo, 1, $page);
        $task->forceFill(['copy_revision_id' => $revisionTwo->id])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.pages.0.assets.clean_master.current_version', null)
            ->assertJsonPath('data.pages.0.assets.clean_master.latest_version.version_no', 2);
        $third = $this->postJson("$url/versions", $this->payload($page, 'masters/new.png'))->assertCreated()
            ->assertJsonPath('data.version_no', 3)->assertJsonPath('data.copy_revision_id', $revisionTwo->id);
        $assetId = $third->json('data.asset_id');
        $this->getJson("$url/$assetId")->assertOk()->assertJsonCount(3, 'data.versions')
            ->assertJsonPath('data.versions.0.version_no', 3)
            ->assertJsonPath('data.versions.2.copy_revision_id', $revisionOne->id);
        $this->assertSame(3, DB::table('asset_versions')->where('copy_revision_id', $revisionOne->id)->count());
        $this->assertSame(ArtworkStatus::NotStarted, $task->fresh()->artwork_status);
    }

    public function test_stale_production_can_append_to_its_pinned_revision(): void
    {
        [, $item, $task, $url] = $this->context();
        $old = $this->pinned($item, $task);
        $page = $this->formalPage($item, $old);
        ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 2]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.is_copy_revision_current', false);
        $this->postJson("$url/versions", $this->payload($page))->assertCreated()->assertJsonPath('data.copy_revision_id', $old->id);
    }

    public function test_validation_and_path_normalization(): void
    {
        [, $item, $task, $url] = $this->context();
        $page = $this->formalPage($item, $this->pinned($item, $task));
        foreach (['id', 'project_id', 'content_item_id', 'production_task_id', 'asset_id', 'copy_revision_id', 'file_id', 'version_no'] as $field) {
            $this->postJson("$url/versions", [...$this->payload($page), $field => 999])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson("$url/versions", $this->payload($page, 'masters/x.png', 'video'))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        foreach (['C:\\images\\x.png', '/absolute/x.png', '\\\\server\\x.png', '../x.png', 'a/../x.png', ' / / '] as $path) {
            $this->postJson("$url/versions", $this->payload($page, $path))
                ->assertUnprocessable()->assertJsonValidationErrors('storage_path');
        }
        foreach (['folder/x.png', 'folder\\x.png', '.', '..'] as $name) {
            $this->postJson("$url/versions", [...$this->payload($page), 'original_name' => $name])
                ->assertUnprocessable()->assertJsonValidationErrors('original_name');
        }
        $this->postJson("$url/versions", $this->payload($page, ' ./masters\\//x.png/ '))
            ->assertCreated()->assertJsonPath('data.file.storage_path', 'masters/x.png');
        $this->postJson("$url/versions", [...$this->payload($page, 'masters/x.png'), 'original_name' => 'different.png'])
            ->assertUnprocessable()->assertJsonValidationErrors('storage_path');
        $this->assertDatabaseCount('files', 1);
    }

    public function test_asset_detail_and_session_scope_reject_other_ancestry(): void
    {
        [$project, $item, $task, $url] = $this->context();
        $page = $this->formalPage($item, $this->pinned($item, $task));
        $id = $this->postJson("$url/versions", $this->payload($page))->assertCreated()->json('data.asset_id');
        $other = ContentItem::factory()->create(['project_id' => $project->id]);
        $otherTask = ProductionTask::factory()->create(['project_id' => $project->id, 'content_item_id' => $other->id]);
        $otherPage = ContentPage::factory()->create(['project_id' => $project->id, 'content_item_id' => $other->id]);
        $wrongItemAsset = Asset::factory()->forProductionPage($otherTask, $otherPage)->create();
        $this->getJson("$url/{$wrongItemAsset->id}")->assertNotFound();
        $crossAsset = Asset::factory()->create();
        $this->getJson("$url/{$crossAsset->id}")->assertNotFound();
        $this->postJson('/api/projects/'.Project::factory()->create()->id.'/select')->assertOk();
        $this->getJson($url)->assertNotFound();
        $this->getJson("$url/$id")->assertNotFound();
        $this->postJson("$url/versions", $this->payload($page))->assertNotFound();
    }
}
