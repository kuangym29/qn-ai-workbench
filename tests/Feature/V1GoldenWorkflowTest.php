<?php

namespace Tests\Feature;

use App\Models\AssetVersion;
use App\Models\ChannelAssetBinding;
use App\Models\ContentCopyRevision;
use App\Models\ContentPageVersion;
use App\Models\DuplicateReviewDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V1GoldenWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
        $user = User::factory()->create(['password' => 'golden-password']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'golden-password'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data', null);
    }

    private function hierarchy(string $slug): array
    {
        $project = $this->postJson('/api/projects', ['name' => "Project {$slug}", 'slug' => $slug])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/projects/{$project}/select")->assertOk()->assertJsonPath('data.id', $project);
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data.id', $project);
        $column = $this->postJson("/api/projects/{$project}/columns", [
            'name' => "Column {$slug}", 'slug' => "column-{$slug}",
        ])->assertCreated()->assertJsonPath('data.project_id', $project)->json('data.id');
        $topic = $this->postJson("/api/projects/{$project}/columns/{$column}/topics", [
            'title' => "Topic {$slug}",
        ])->assertCreated()->assertJsonPath('data.project_id', $project)
            ->assertJsonPath('data.content_column_id', $column)->json('data.id');

        return [$project, $column, $topic];
    }

    private function item(int $project, int $column, int $topic, string $title): array
    {
        $list = "/api/projects/{$project}/columns/{$column}/topics/{$topic}/items";
        $id = $this->postJson($list, ['title' => $title])->assertCreated()
            ->assertJsonPath('data.project_id', $project)
            ->assertJsonPath('data.content_column_id', $column)
            ->assertJsonPath('data.topic_id', $topic)
            ->assertJsonPath('data.copy_status', 'not_started')->json('data.id');

        return [$id, "{$list}/{$id}"];
    }

    private function page(string $itemUrl, int $number, string $type, array $copy): array
    {
        $id = $this->postJson("{$itemUrl}/pages", ['page_no' => $number, 'page_type' => $type])
            ->assertCreated()->assertJsonPath('data.page_no', $number)
            ->assertJsonPath('data.page_type', $type)->json('data.id');
        $version = $this->postJson("{$itemUrl}/pages/{$id}/drafts", $copy)
            ->assertCreated()->assertJsonPath('data.copy_revision_id', null)->json('data.id');

        return [$id, $version];
    }

    private function asset(string $productionUrl, int $page, string $role, string $path): int
    {
        return $this->postJson("{$productionUrl}/assets/versions", [
            'content_page_id' => $page,
            'role' => $role,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'image/png',
            'size_bytes' => 128,
            'width' => 100,
            'height' => 100,
        ])->assertCreated()->assertJsonPath('data.version_no', 1)->json('data.id');
    }

    public function test_golden_workflow_from_guest_to_both_published_channels_and_logout(): void
    {
        $this->login();
        [$project, $column, $topic] = $this->hierarchy('golden-main');

        // A formal item in the same Project supplies a real historical duplicate candidate.
        [$historyId, $historyUrl] = $this->item($project, $column, $topic, 'Historical article');
        [, $historicalDraft] = $this->page($historyUrl, 1, 'content', ['page_title' => 'A repeated golden sentence']);
        $historyRevision = $this->postJson("{$historyUrl}/copy/confirm")->assertCreated()
            ->assertJsonPath('data.revision_no', 1)->json('data');
        $historicalVersion = $historyRevision['page_versions'][0]['id'];
        $this->assertNotSame($historicalDraft, $historicalVersion);
        $this->assertDatabaseHas('content_copy_revisions', ['id' => $historyRevision['id'], 'content_item_id' => $historyId]);

        [$itemId, $itemUrl] = $this->item($project, $column, $topic, 'Golden article');
        [$cover] = $this->page($itemUrl, 1, 'cover', ['cover_title' => 'Golden cover']);
        [$body, $firstDraft] = $this->page($itemUrl, 2, 'content', ['page_title' => 'A repeated golden sentence']);
        $queryVersion = $this->postJson("{$itemUrl}/pages/{$body}/drafts", ['page_small_text' => 'Additional copy'])
            ->assertCreated()->assertJsonPath('data.version_no', 2)
            ->assertJsonPath('data.page_title', 'A repeated golden sentence')->json('data.id');
        $this->assertNotSame($firstDraft, $queryVersion);
        [$closing] = $this->page($itemUrl, 3, 'column_closing', ['closing_line' => 'Golden closing line']);
        [$back] = $this->page($itemUrl, 4, 'fixed_back_cover', ['note' => 'Fixed back cover']);
        $pages = [$cover, $body, $closing, $back];
        $this->getJson("{$itemUrl}/copy/working")->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.1.latest_version.id', $queryVersion)
            ->assertJsonPath('data.3.page_type', 'fixed_back_cover');

        $reviewUrl = "{$itemUrl}/duplicate-review";
        $review = $this->getJson($reviewUrl)->assertOk()->assertJsonPath('data.candidate_count', 1)->json('data');
        $this->assertNotContains($back, array_column($review['queries'], 'content_page_id'));
        $query = collect($review['queries'])->firstWhere('page_version_id', $queryVersion);
        $this->assertNotNull($query);
        $candidate = $query['candidates'][0];
        $this->assertSame('original_exact', $candidate['match_kind']);
        $this->assertGreaterThan(0, $candidate['threshold']);
        $this->assertSame('A repeated golden sentence', $query['text']);
        $this->assertSame($query['text'], $candidate['match']['text']);
        $this->assertSame($historyRevision['id'], $candidate['match']['copy_revision_id']);
        $this->assertSame($historicalVersion, $candidate['match']['page_version_id']);

        $decision = [
            'query_page_version_id' => $queryVersion,
            'query_field' => 'page_title',
            'match_page_version_id' => $historicalVersion,
            'match_field' => 'page_title',
        ];
        $firstDecision = $this->postJson("{$reviewUrl}/decisions", [...$decision, 'decision' => 'confirmed_duplicate'])
            ->assertCreated()->assertJsonPath('data.decision_no', 1)->json('data.id');
        $this->postJson("{$reviewUrl}/decisions", [...$decision, 'decision' => 'ignored'])
            ->assertCreated()->assertJsonPath('data.decision_no', 2);
        $latestQueries = $this->getJson($reviewUrl)->assertOk()->json('data.queries');
        $latestQuery = collect($latestQueries)->firstWhere('page_version_id', $queryVersion);
        $this->assertSame(2, $latestQuery['candidates'][0]['latest_decision']['decision_no']);
        $this->assertSame('ignored', $latestQuery['candidates'][0]['latest_decision']['decision']);
        $this->assertDatabaseHas('duplicate_review_decisions', ['id' => $firstDecision, 'decision' => 'confirmed_duplicate']);

        $revision = $this->postJson("{$itemUrl}/copy/confirm")->assertCreated()
            ->assertJsonPath('data.revision_no', 1)->assertJsonCount(4, 'data.page_versions')->json('data');
        $revisionId = $revision['id'];
        $this->assertSame($pages, array_column($revision['page_versions'], 'content_page_id'));
        foreach ($revision['page_versions'] as $snapshot) {
            $this->assertSame($revisionId, $snapshot['copy_revision_id']);
            $this->assertNotNull($snapshot['page_no_snapshot']);
            $this->assertNotNull($snapshot['page_type_snapshot']);
            $this->assertNotSame($queryVersion, $snapshot['id']);
        }
        $this->assertDatabaseHas('content_items', ['id' => $itemId, 'copy_status' => 'confirmed']);
        $this->getJson("{$itemUrl}/copy/current")->assertOk()->assertJsonPath('data.id', $revisionId);
        $this->getJson("{$itemUrl}/copy/working")->assertOk()
            ->assertJsonPath('data.1.latest_version.copy_revision_id', $revisionId);

        $productionUrl = "{$itemUrl}/production";
        $productionId = $this->postJson($productionUrl)->assertCreated()
            ->assertJsonPath('data.copy_revision_id', $revisionId)
            ->assertJsonPath('data.artwork_status', 'not_started')
            ->assertJsonPath('data.is_copy_revision_current', true)->json('data.id');
        $this->patchJson($productionUrl, ['artwork_status' => 'in_progress'])->assertOk();
        $this->patchJson($productionUrl, ['artwork_status' => 'pending_review'])->assertOk();
        $this->patchJson($productionUrl, ['artwork_status' => 'approved'])->assertOk();

        $officialUrl = "{$productionUrl}/channels/wechat_official";
        $videoUrl = "{$productionUrl}/channels/wechat_channels";
        $this->postJson("{$productionUrl}/channels", ['channel' => 'wechat_official'])->assertCreated()
            ->assertJsonPath('data.production_task_id', $productionId);
        $this->postJson("{$productionUrl}/channels", ['channel' => 'wechat_channels'])->assertCreated()
            ->assertJsonPath('data.production_task_id', $productionId);
        $this->assertDatabaseCount('production_tasks', 1);
        $this->assertDatabaseCount('channel_tasks', 2);
        $this->patchJson("{$officialUrl}/publish", ['publish_status' => 'published'])
            ->assertUnprocessable();
        $this->patchJson("{$videoUrl}/video", ['video_status' => 'approved'])
            ->assertUnprocessable();

        $officialVersions = [];
        $videoVersions = [];
        foreach ($pages as $number => $page) {
            $officialVersions[$page] = $this->asset($productionUrl, $page, 'copy_master', "golden/copy-{$number}.png");
            $videoVersions[$page] = $this->asset($productionUrl, $page, 'clean_master', "golden/clean-{$number}.png");
            $this->assertDatabaseHas('asset_versions', [
                'id' => $officialVersions[$page], 'copy_revision_id' => $revisionId,
            ]);
        }
        $this->getJson("{$productionUrl}/assets")->assertOk()
            ->assertJsonPath('data.copy_revision_id', $revisionId)
            ->assertJsonPath('data.pages.0.assets.copy_master.role', 'copy_master')
            ->assertJsonPath('data.pages.0.assets.clean_master.role', 'clean_master');
        $this->getJson("{$officialUrl}/assets")->assertOk()
            ->assertJsonPath('data.expected_asset_role', 'copy_master')
            ->assertJsonPath('data.total_page_count', 4);
        $this->getJson("{$videoUrl}/assets")->assertOk()
            ->assertJsonPath('data.expected_asset_role', 'clean_master');
        $this->postJson("{$officialUrl}/assets/bindings", [
            'content_page_id' => $cover, 'asset_version_id' => $videoVersions[$cover],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_version_id');
        $this->postJson("{$videoUrl}/assets/bindings", [
            'content_page_id' => $cover, 'asset_version_id' => $officialVersions[$cover],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_version_id');
        foreach ($pages as $page) {
            $this->postJson("{$officialUrl}/assets/bindings", [
                'content_page_id' => $page, 'asset_version_id' => $officialVersions[$page],
            ])->assertCreated()->assertJsonPath('data.binding_no', 1);
            $this->postJson("{$videoUrl}/assets/bindings", [
                'content_page_id' => $page, 'asset_version_id' => $videoVersions[$page],
            ])->assertCreated()->assertJsonPath('data.binding_no', 1);
        }
        $this->getJson("{$officialUrl}/assets")->assertOk()
            ->assertJsonPath('data.is_complete', true)
            ->assertJsonPath('data.bound_page_count', 4)
            ->assertJsonPath('data.pages.0.current_binding.asset_version.id', $officialVersions[$cover])
            ->assertJsonPath('data.pages.0.latest_binding.asset_version.id', $officialVersions[$cover]);
        $this->getJson("{$videoUrl}/assets")->assertOk()
            ->assertJsonPath('data.is_complete', true)->assertJsonPath('data.bound_page_count', 4);

        $this->patchJson("{$videoUrl}/video", ['video_status' => 'in_progress'])->assertOk();
        $this->patchJson("{$videoUrl}/video", ['video_status' => 'pending_review'])->assertOk();
        $this->patchJson("{$videoUrl}/video", ['video_status' => 'approved'])->assertOk();
        $this->patchJson("{$officialUrl}/publish", [
            'publish_status' => 'scheduled', 'scheduled_at' => '2026-10-10T10:00:00+08:00',
        ])->assertOk()->assertJsonPath('data.publish_status', 'scheduled');
        $officialPublished = $this->patchJson("{$officialUrl}/publish", ['publish_status' => 'published'])
            ->assertOk()->assertJsonPath('data.publish_status', 'published')->json('data.published_at');
        $videoPublished = $this->patchJson("{$videoUrl}/publish", ['publish_status' => 'published'])
            ->assertOk()->assertJsonPath('data.publish_status', 'published')->json('data.published_at');
        $this->assertNotNull($officialPublished);
        $this->assertNotNull($videoPublished);
        $this->patchJson("{$officialUrl}/publish", ['publish_status' => 'published'])
            ->assertOk()->assertJsonPath('data.published_at', $officialPublished);
        $this->patchJson("{$videoUrl}/publish", ['publish_status' => 'published'])
            ->assertOk()->assertJsonPath('data.published_at', $videoPublished);
        $this->patchJson("{$officialUrl}/publish", ['publish_status' => 'unpublished'])->assertUnprocessable();

        // A later formal copy does not rewrite published history or the old decision.
        $this->postJson("{$itemUrl}/pages/{$body}/drafts", ['page_title' => 'Revised golden sentence'])
            ->assertCreated()->assertJsonPath('data.copy_revision_id', null);
        $newRevision = $this->postJson("{$itemUrl}/copy/confirm")->assertCreated()
            ->assertJsonPath('data.revision_no', 2)->json('data.id');
        $this->getJson($productionUrl)->assertOk()
            ->assertJsonPath('data.copy_revision_id', $revisionId)
            ->assertJsonPath('data.is_copy_revision_current', false);
        $this->getJson("{$officialUrl}/assets")->assertOk()
            ->assertJsonPath('data.is_production_copy_current', false)
            ->assertJsonPath('data.pages.0.latest_binding.asset_version.copy_revision_id', $revisionId);
        $this->assertDatabaseHas('duplicate_review_decisions', ['id' => $firstDecision, 'query_page_version_id' => $queryVersion]);
        $this->assertDatabaseHas('content_copy_revisions', ['id' => $newRevision, 'content_item_id' => $itemId]);
        $this->assertSame(8, AssetVersion::where('copy_revision_id', $revisionId)->count());

        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->assertNull(session('current_project_id'));
        $user = User::firstOrFail();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'golden-password'])
            ->assertOk();
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data', null);
    }

    public function test_restart_keeps_old_binding_as_history_and_rejects_cross_project_version(): void
    {
        $this->login();
        [$project, $column, $topic] = $this->hierarchy('golden-isolation');
        [$itemId, $itemUrl] = $this->item($project, $column, $topic, 'Restartable article');
        [$page] = $this->page($itemUrl, 1, 'content', ['page_title' => 'First revision']);
        $revisionOne = $this->postJson("{$itemUrl}/copy/confirm")->assertCreated()->json('data.id');
        $productionUrl = "{$itemUrl}/production";
        $this->postJson($productionUrl)->assertCreated()->assertJsonPath('data.copy_revision_id', $revisionOne);
        $this->patchJson($productionUrl, ['artwork_status' => 'approved'])->assertOk();
        $this->postJson("{$productionUrl}/channels", ['channel' => 'wechat_official'])->assertCreated();
        $oldVersion = $this->asset($productionUrl, $page, 'copy_master', 'isolation/old.png');
        $bindingUrl = "{$productionUrl}/channels/wechat_official/assets";
        $oldBinding = $this->postJson("{$bindingUrl}/bindings", [
            'content_page_id' => $page, 'asset_version_id' => $oldVersion,
        ])->assertCreated()->json('data.id');

        // Build Project B entirely through HTTP, then attack Project A with B's version.
        [$projectB, $columnB, $topicB] = $this->hierarchy('golden-foreign');
        [, $itemUrlB] = $this->item($projectB, $columnB, $topicB, 'Foreign article');
        [$pageB] = $this->page($itemUrlB, 1, 'content', ['page_title' => 'Foreign page']);
        $this->postJson("{$itemUrlB}/copy/confirm")->assertCreated();
        $productionB = "{$itemUrlB}/production";
        $this->postJson($productionB)->assertCreated();
        $foreignVersion = $this->asset($productionB, $pageB, 'copy_master', 'isolation/foreign.png');
        $this->postJson("/api/projects/{$project}/select")->assertOk();
        $this->postJson("{$bindingUrl}/bindings", [
            'content_page_id' => $page, 'asset_version_id' => $foreignVersion,
        ])->assertNotFound();
        $this->getJson("{$itemUrlB}/copy/current")->assertNotFound();

        $this->postJson("{$itemUrl}/pages/{$page}/drafts", ['page_title' => 'Second revision'])->assertCreated();
        $revisionTwo = $this->postJson("{$itemUrl}/copy/confirm")->assertCreated()
            ->assertJsonPath('data.revision_no', 2)->json('data.id');
        $this->getJson($productionUrl)->assertOk()->assertJsonPath('data.is_copy_revision_current', false);
        $this->getJson($bindingUrl)->assertOk()->assertJsonPath('data.is_production_copy_current', false)
            ->assertJsonPath('data.pages.0.current_binding.id', $oldBinding);
        $this->postJson("{$productionUrl}/restart-with-current-copy")->assertOk()
            ->assertJsonPath('data.production.copy_revision_id', $revisionTwo);
        $this->getJson($bindingUrl)->assertOk()
            ->assertJsonPath('data.is_complete', false)
            ->assertJsonPath('data.pages.0.current_binding', null)
            ->assertJsonPath('data.pages.0.latest_binding.id', $oldBinding);
        $this->assertDatabaseHas('channel_asset_bindings', ['id' => $oldBinding, 'asset_version_id' => $oldVersion]);
        $this->assertDatabaseHas('asset_versions', ['id' => $oldVersion, 'copy_revision_id' => $revisionOne]);
        $this->assertSame(1, ChannelAssetBinding::where('content_page_id', $page)->count());
        $this->assertSame(2, ContentCopyRevision::where('content_item_id', $itemId)->count());
        $this->assertSame(2, ContentPageVersion::where('content_page_id', $page)
            ->whereNotNull('copy_revision_id')->count());
        $this->assertSame(0, DuplicateReviewDecision::where('content_item_id', $itemId)->count());
    }
}
