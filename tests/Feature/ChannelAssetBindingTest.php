<?php

namespace Tests\Feature;

use App\Enums\ArtworkStatus;
use App\Enums\CopyStatus;
use App\Enums\PublishStatus;
use App\Enums\VideoStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\ChannelAssetBinding;
use App\Models\ChannelTask;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ChannelAssetBindingTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $production = true): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
            'copy_status' => CopyStatus::Confirmed,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $url = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/production";
        $task = $production ? ProductionTask::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $item->id,
            'artwork_status' => ArtworkStatus::Approved,
        ]) : null;

        return [$project, $item, $task, $url];
    }

    private function revision(ContentItem $item, ProductionTask $task, int $number = 1): ContentCopyRevision
    {
        $revision = ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => $number,
        ]);
        $task->forceFill(['copy_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function page(ContentItem $item, ContentCopyRevision $revision, int $number, ?ContentPage $existing = null): ContentPage
    {
        $page = $existing ?? ContentPage::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'page_no' => $number,
        ]);
        ContentPageVersion::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'content_page_id' => $page->id, 'copy_revision_id' => $revision->id,
            'version_no' => $page->versions()->count() + 1,
            'page_no_snapshot' => $number, 'page_type_snapshot' => 'cover',
        ]);

        return $page;
    }

    private function channel(ProductionTask $task, bool $video = false): ChannelTask
    {
        $factory = ChannelTask::factory();
        if ($video) {
            $factory = $factory->wechatChannels();
        }

        return $factory->create(['project_id' => $task->project_id, 'production_task_id' => $task->id]);
    }

    private function version(ProductionTask $task, ContentPage $page, ContentCopyRevision $revision, bool $clean = false, ?Asset $asset = null, int $number = 1): AssetVersion
    {
        $asset ??= ($clean ? Asset::factory()->cleanMaster() : Asset::factory()->copyMaster())
            ->forProductionPage($task, $page)->create();

        return AssetVersion::factory()->forAssetAndCopyRevision($asset, $revision)->create(['version_no' => $number]);
    }

    public function test_missing_parent_and_channel_return_404_and_legacy_is_empty(): void
    {
        [, $item, , $url] = $this->context(false);
        $path = "$url/channels/wechat_official/assets";
        $this->getJson($path)->assertNotFound();
        $this->postJson("$path/bindings", ['content_page_id' => 1, 'asset_version_id' => 1])->assertNotFound();
        $task = ProductionTask::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $this->getJson($path)->assertNotFound();
        $this->getJson("$url/channels/unknown/assets")->assertNotFound();
        $this->channel($task);
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data.pages')
            ->assertJsonPath('data.total_page_count', 0)->assertJsonPath('data.is_complete', false);
        $this->postJson("$path/bindings", ['content_page_id' => 1, 'asset_version_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('production');
    }

    public function test_official_workspace_uses_snapshot_and_append_only_bindings(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task);
        $pageOne = $this->page($item, $revision, 1);
        $pageTwo = $this->page($item, $revision, 2);
        $pageOne->update(['page_no' => 3, 'page_type' => 'content']);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $this->getJson($path)->assertOk()->assertJsonPath('data.expected_asset_role', 'copy_master')
            ->assertJsonPath('data.pages.0.page_no', 1)->assertJsonPath('data.pages.0.page_type', 'cover')
            ->assertJsonPath('data.pages.0.asset_id', null)->assertJsonCount(0, 'data.pages.0.available_versions')
            ->assertJsonPath('data.bound_page_count', 0)->assertJsonPath('data.total_page_count', 2);
        $v1 = $this->version($task, $pageOne, $revision);
        $v2 = $this->version($task, $pageOne, $revision, asset: $v1->asset, number: 2);
        $this->getJson($path)->assertOk()->assertJsonPath('data.pages.0.available_versions.0.id', $v2->id)
            ->assertJsonPath('data.pages.0.available_versions.1.id', $v1->id);
        $first = $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $v1->id])
            ->assertCreated()->assertJsonPath('data.binding_no', 1)->assertJsonPath('data.asset_version.id', $v1->id);
        $this->postJson("$path/bindings", ['content_page_id' => $pageOne->id, 'asset_version_id' => $v2->id])
            ->assertCreated()->assertJsonPath('data.binding_no', 2);
        $this->getJson($path)->assertOk()->assertJsonPath('data.pages.0.current_binding.binding_no', 2)
            ->assertJsonPath('data.pages.0.latest_binding.binding_no', 2)
            ->assertJsonPath('data.is_complete', false)->assertJsonPath('data.bound_page_count', 1);
        $v3 = $this->version($task, $pageTwo, $revision);
        $this->postJson("$path/bindings", ['content_page_id' => $pageTwo->id, 'asset_version_id' => $v3->id])
            ->assertCreated()->assertJsonPath('data.binding_no', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('data.is_complete', true)
            ->assertJsonPath('data.bound_page_count', 2);
        $this->assertDatabaseCount('channel_asset_bindings', 3);
        $this->assertDatabaseHas('channel_asset_bindings', ['id' => $first->json('data.id'), 'asset_version_id' => $v1->id]);
        $this->assertSame(ArtworkStatus::Approved, $task->fresh()->artwork_status);
    }

    public function test_scope_role_revision_and_payload_gates(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task);
        $page = $this->page($item, $revision, 1);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $valid = $this->version($task, $page, $revision);
        foreach (['role', 'asset_id', 'copy_revision_id', 'binding_no', 'project_id', 'content_item_id', 'production_task_id', 'channel_task_id'] as $field) {
            $this->postJson("$path/bindings", [
                'content_page_id' => $page->id, 'asset_version_id' => $valid->id, $field => 999,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $newPage = ContentPage::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'page_no' => 2]);
        $this->postJson("$path/bindings", ['content_page_id' => $newPage->id, 'asset_version_id' => $valid->id])
            ->assertUnprocessable()->assertJsonValidationErrors('content_page_id');
        $this->page($item, $revision, 2, $newPage);
        $this->postJson("$path/bindings", ['content_page_id' => $newPage->id, 'asset_version_id' => $valid->id])
            ->assertUnprocessable()->assertJsonValidationErrors('asset_version_id');
        $wrongRole = $this->version($task, $page, $revision, clean: true);
        $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $wrongRole->id])
            ->assertUnprocessable()->assertJsonValidationErrors('asset_version_id');
        $newRevision = ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 2]);
        $wrongRevision = $this->version($task, $newPage, $newRevision);
        $this->postJson("$path/bindings", ['content_page_id' => $newPage->id, 'asset_version_id' => $wrongRevision->id])
            ->assertUnprocessable()->assertJsonValidationErrors('asset_version_id');
        $otherItem = ContentItem::factory()->create(['project_id' => $item->project_id]);
        $otherPage = ContentPage::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $otherItem->id]);
        $this->postJson("$path/bindings", ['content_page_id' => $otherPage->id, 'asset_version_id' => $valid->id])->assertNotFound();
        $foreignVersion = AssetVersion::factory()->create();
        $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $foreignVersion->id])->assertNotFound();
        $this->postJson('/api/projects/'.Project::factory()->create()->id.'/select')->assertOk();
        $this->getJson($path)->assertNotFound();
        $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $valid->id])->assertNotFound();
    }

    public function test_stale_and_restart_preserve_history_without_current_binding(): void
    {
        [, $item, $task, $url] = $this->context();
        $r1 = $this->revision($item, $task);
        $page = $this->page($item, $r1, 1);
        $this->channel($task);
        $path = "$url/channels/wechat_official/assets";
        $v1 = $this->version($task, $page, $r1);
        $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $v1->id])->assertCreated();
        $r2 = ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 2]);
        $this->page($item, $r2, 1, $page);
        $this->getJson($path)->assertOk()->assertJsonPath('data.is_production_copy_current', false)
            ->assertJsonPath('data.is_complete', true);
        $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $v1->id])->assertCreated();
        $this->postJson("$url/restart-with-current-copy")->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.is_complete', false)
            ->assertJsonPath('data.pages.0.current_binding', null)
            ->assertJsonPath('data.pages.0.latest_binding.binding_no', 2)
            ->assertJsonCount(0, 'data.pages.0.available_versions');
        $v2 = $this->version($task, $page, $r2, asset: $v1->asset, number: 2);
        $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $v2->id])
            ->assertCreated()->assertJsonPath('data.binding_no', 3);
        $this->getJson($path)->assertOk()->assertJsonPath('data.pages.0.current_binding.binding_no', 3)
            ->assertJsonPath('data.pages.0.latest_binding.binding_no', 3)
            ->assertJsonCount(1, 'data.pages.0.available_versions');
        $this->assertDatabaseCount('channel_asset_bindings', 3);
    }

    public function test_terminal_gates_and_published_no_op(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task);
        $page = $this->page($item, $revision, 1);
        $official = $this->channel($task);
        $video = $this->channel($task, true);
        $officialPath = "$url/channels/wechat_official";
        $videoPath = "$url/channels/wechat_channels";
        $schedule = ['publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00Z'];
        $this->patchJson("$officialPath/publish", $schedule)->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        $this->patchJson("$officialPath/publish", ['publish_status' => 'published'])->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        $this->patchJson("$videoPath/video", ['video_status' => 'in_progress'])->assertOk();
        $this->patchJson("$videoPath/video", ['video_status' => 'pending_review'])->assertOk();
        $this->patchJson("$videoPath/video", ['video_status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('video_status');
        $this->patchJson("$videoPath/publish", $schedule)->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        DB::table('channel_tasks')->where('id', $video->id)->update(['video_status' => VideoStatus::Approved->value]);
        $this->patchJson("$videoPath/publish", $schedule)->assertUnprocessable()->assertJsonValidationErrors('publish_status');
        DB::table('channel_tasks')->where('id', $video->id)->update(['video_status' => VideoStatus::PendingReview->value]);
        $this->version($task, $page, $revision);
        $copy = $task->assets()->where('role', 'copy_master')->firstOrFail()->versions()->firstOrFail();
        $clean = $this->version($task, $page, $revision, clean: true);
        $this->postJson("$officialPath/assets/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $copy->id])->assertCreated();
        $this->postJson("$videoPath/assets/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $clean->id])->assertCreated();
        $this->patchJson("$videoPath/video", ['video_status' => 'approved'])->assertOk();
        $this->patchJson("$officialPath/publish", $schedule)->assertOk();
        $this->patchJson("$videoPath/publish", $schedule)->assertOk();
        $this->patchJson("$officialPath/publish", ['publish_status' => 'published'])->assertOk();
        $publishedAt = $official->fresh()->published_at;
        $this->patchJson("$officialPath/publish", ['publish_status' => 'published'])->assertOk();
        $this->assertEquals($publishedAt, $official->fresh()->published_at);
        $this->assertSame(VideoStatus::Approved, $video->fresh()->video_status);
        $this->assertSame(PublishStatus::Scheduled, $video->fresh()->publish_status);
        $this->assertSame(ArtworkStatus::Approved, $task->fresh()->artwork_status);
    }

    public function test_database_scope_immutability_and_no_update_delete_routes(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task);
        $page = $this->page($item, $revision, 1);
        $channel = $this->channel($task);
        $version = $this->version($task, $page, $revision);
        $path = "$url/channels/wechat_official/assets";
        $id = $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $version->id])
            ->assertCreated()->json('data.id');
        $binding = ChannelAssetBinding::findOrFail($id);
        foreach ([fn () => $binding->update(['binding_no' => 99]), fn () => $binding->delete()] as $change) {
            try {
                $change();
                $this->fail('Binding should be immutable.');
            } catch (LogicException $error) {
                $this->assertSame('Channel asset bindings are immutable.', $error->getMessage());
            }
        }
        $this->assertDatabaseHas('channel_asset_bindings', ['id' => $id, 'binding_no' => 1]);
        $this->patchJson("$path/$id", ['binding_no' => 3])->assertNotFound();
        $this->deleteJson("$path/$id")->assertNotFound();
        $base = DB::table('channel_asset_bindings')->where('id', $id)->first();
        try {
            DB::table('channel_asset_bindings')->insert([
                'project_id' => $base->project_id,
                'content_item_id' => $base->content_item_id,
                'production_task_id' => $base->production_task_id,
                'channel_task_id' => $channel->id,
                'content_page_id' => $base->content_page_id,
                'asset_id' => $base->asset_id,
                'asset_version_id' => $base->asset_version_id,
                'copy_revision_id' => $base->copy_revision_id,
                'binding_no' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Expected binding number uniqueness.');
        } catch (QueryException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertDatabaseCount('channel_asset_bindings', 1);
    }

    public function test_database_composite_foreign_keys_reject_wrong_scope(): void
    {
        [, $item, $task, $url] = $this->context();
        $revision = $this->revision($item, $task);
        $page = $this->page($item, $revision, 1);
        $this->channel($task);
        $version = $this->version($task, $page, $revision);
        $path = "$url/channels/wechat_official/assets";
        $id = $this->postJson("$path/bindings", ['content_page_id' => $page->id, 'asset_version_id' => $version->id])
            ->assertCreated()->json('data.id');
        $base = (array) DB::table('channel_asset_bindings')->where('id', $id)->first();
        unset($base['id']);
        $otherItem = ContentItem::factory()->create();
        $otherTask = ProductionTask::factory()->create([
            'project_id' => $otherItem->project_id, 'content_item_id' => $otherItem->id,
        ]);
        $otherChannel = $this->channel($otherTask);
        $otherPage = ContentPage::factory()->create([
            'project_id' => $otherItem->project_id, 'content_item_id' => $otherItem->id,
        ]);
        $otherRevision = ContentCopyRevision::factory()->create([
            'project_id' => $otherItem->project_id, 'content_item_id' => $otherItem->id,
        ]);
        $otherVersion = $this->version($otherTask, $otherPage, $otherRevision);
        $sameItemRevision = ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => 2,
        ]);
        $sameAssetNewVersion = $this->version($task, $page, $sameItemRevision, asset: $version->asset, number: 2);
        foreach ([
            ['project_id' => $otherItem->project_id],
            ['content_item_id' => $otherItem->id],
            ['production_task_id' => $otherTask->id],
            ['channel_task_id' => $otherChannel->id],
            ['content_page_id' => $otherPage->id],
            ['asset_id' => $otherVersion->asset_id],
            ['copy_revision_id' => $sameItemRevision->id],
            ['asset_version_id' => $sameAssetNewVersion->id],
        ] as $wrong) {
            try {
                DB::table('channel_asset_bindings')->insert([...$base, ...$wrong, 'binding_no' => 2]);
                $this->fail('Expected composite foreign key rejection.');
            } catch (QueryException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertDatabaseCount('channel_asset_bindings', 1);
    }
}
