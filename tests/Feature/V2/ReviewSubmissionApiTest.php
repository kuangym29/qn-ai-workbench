<?php

namespace Tests\Feature\V2;

use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ImageBatchDraft;
use App\Models\ReusableAsset;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupTarget(string $target): array
    {
        $item = ContentItem::factory()->create(['title' => '正式标题']);
        $branch = ContentBranch::factory()->for($item)->create([
            'format' => match ($target) {
                'article_copy' => 'article','video_production' => 'video',default => 'image_post'
            },
            'channel' => $target === 'video_production' ? 'wechat_channels' : 'wechat_official',
        ]);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/review-submissions/{$target}";

        return [$item, $branch, $url];
    }

    private function saveTarget(string $target, ContentItem $item, ContentBranch $branch): mixed
    {
        $scope = ['project_id' => $item->project_id, 'content_item_id' => $item->id, 'content_branch_id' => $branch->id];

        return match ($target) {
            'article_copy' => ArticleContent::create([...$scope, 'headline' => '标题', 'summary' => '摘要', 'body' => '正文', 'image_suggestions' => [['position' => '开头', 'description' => '图一']], 'cover_suggestion' => '封面']),
            'video_production' => VideoContent::create([...$scope, 'batch_instruction' => '视频说明', 'narration' => '旁白', 'units' => [['source_page_id' => 7, 'dynamic_instruction' => '推进', 'duration_seconds' => 4, 'note' => '备注']]]),
            'image_batch' => ImageBatchDraft::create([...$scope, 'batch_instruction' => '批次说明', 'batch_note' => '备注']),
            default => ContentPage::factory()->for($item)->create(['page_no' => 1, 'final_copy' => '最终文字', 'narrative_role' => '承接', 'visual_evidence' => '画面']),
        };
    }

    public function test_four_saved_targets_submit_and_duplicate_pending_is_idempotent(): void
    {
        foreach (['image_post_copy', 'article_copy', 'video_production', 'image_batch'] as $target) {
            [$item,$branch,$url] = $this->setupTarget($target);
            $this->saveTarget($target, $item, $branch);
            $this->getJson($url)->assertOk()->assertJsonPath('data.pending', false);
            $response = $this->postJson($url)->assertCreated()->assertJsonPath('data.created', true);
            $id = $response->json('data.review.id');
            $this->postJson($url)->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.review.id', $id);
            $this->getJson($url)->assertOk()->assertJsonPath('data.pending', true)->assertJsonPath('data.review.id', $id);
            $this->assertSame('items', ReviewRequest::findOrFail($id)->preview['kind']);
        }
    }

    public function test_unsaved_targets_reject_and_reads_never_produce_reviews(): void
    {
        foreach (['image_post_copy', 'article_copy', 'video_production', 'image_batch'] as $target) {
            [$item,$branch,$url] = $this->setupTarget($target);
            $this->getJson($url)->assertOk()->assertJsonPath('data.pending', false);
            $this->postJson($url)->assertUnprocessable();
        }
        $this->assertDatabaseCount('review_requests', 0);
        $this->assertDatabaseCount('image_batch_drafts', 0);
        $this->assertDatabaseCount('image_batch_runs', 0);
    }

    public function test_content_identity_canonicalizes_object_keys_preserves_lists_and_freezes_preview(): void
    {
        [$item,$branch,$url] = $this->setupTarget('article_copy');
        $article = $this->saveTarget('article_copy', $item, $branch);
        $first = $this->postJson($url)->assertCreated()->json('data.review');
        $frozen = ReviewRequest::findOrFail($first['id'])->preview;
        $article->update(['image_suggestions' => [['description' => '图一', 'position' => '开头']]]);
        $this->postJson($url)->assertOk()->assertJsonPath('data.review.id', $first['id']);
        $article->update(['image_suggestions' => [['position' => '结尾', 'description' => '图二'], ['description' => '图一', 'position' => '开头']]]);
        $second = $this->postJson($url)->assertCreated()->json('data.review');
        $article->update(['image_suggestions' => array_reverse($article->image_suggestions)]);
        $third = $this->postJson($url)->assertCreated()->json('data.review');
        $this->assertNotSame($first['version_label'], $second['version_label']);
        $this->assertNotSame($second['version_label'], $third['version_label']);
        $this->assertSame($frozen, ReviewRequest::findOrFail($first['id'])->preview);
    }

    public function test_decisions_clear_pending_and_changed_saved_version_can_be_resubmitted(): void
    {
        foreach (['approved', 'changes_requested'] as $decision) {
            [$item,$branch,$url] = $this->setupTarget('article_copy');
            $article = $this->saveTarget('article_copy', $item, $branch);
            $first = $this->postJson($url)->assertCreated()->json('data.review');
            $this->postJson("/api/projects/{$item->project_id}/reviews/{$first['id']}/decision", ['decision' => $decision, 'comment' => '请修改'])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.pending', false);
            $article->update(['body' => '修改后的正文']);
            $next = $this->postJson($url)->assertCreated()->json('data.review');
            $this->assertNotSame($first['version_label'], $next['version_label']);
        }
    }

    public function test_strict_scope_target_and_body_validation(): void
    {
        [$item,$branch,$url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        foreach (['preview', 'version_label', 'snapshot', 'content'] as $field) {
            $this->postJson($url, [$field => 'forged'])->assertUnprocessable();
        }
        $this->postJson(str_replace('article_copy', 'image_post_copy', $url))->assertUnprocessable();
        $this->getJson(str_replace('article_copy', 'invalid', $url))->assertUnprocessable();
        $foreign = ContentItem::factory()->create();
        $this->getJson(str_replace("/items/{$item->id}/", "/items/{$foreign->id}/", $url))->assertNotFound();
        $foreignBranch = ContentBranch::factory()->create();
        $this->postJson(str_replace("/branches/{$branch->id}/", "/branches/{$foreignBranch->id}/", $url))->assertNotFound();
        $this->withSession(['current_project_id' => $foreign->project_id])->getJson($url)->assertNotFound();
        $this->assertDatabaseCount('review_requests', 0);
    }

    public function test_unauthenticated_submission_is_rejected(): void
    {
        $branch = ContentBranch::factory()->create();
        $this->postJson("/api/projects/{$branch->project_id}/items/{$branch->content_item_id}/branches/{$branch->id}/review-submissions/image_post_copy")->assertUnauthorized();
    }

    public function test_image_batch_empty_workspace_draft_is_not_saved_and_submission_is_read_only(): void
    {
        [$item,$branch,$url] = $this->setupTarget('image_batch');
        ContentPage::factory()->for($item)->create(['page_no' => 1]);
        $workspace = str_replace('/review-submissions/image_batch', '/image-batch', $url);
        $this->getJson($workspace)->assertOk();
        $this->postJson($url)->assertUnprocessable();
        $this->putJson($workspace, ['batch_instruction' => '正式说明', 'units' => []])->assertOk();
        $before = DB::table('image_batch_drafts')->get()->toJson();
        $settings = DB::table('image_batch_unit_settings')->get()->toJson();
        $this->postJson($url)->assertCreated();
        $this->assertSame($before, DB::table('image_batch_drafts')->get()->toJson());
        $this->assertSame($settings, DB::table('image_batch_unit_settings')->get()->toJson());
        $this->assertDatabaseCount('image_batch_runs', 0);
    }

    public function test_each_target_saved_change_changes_identity_and_freezes_all_required_content(): void
    {
        foreach (['image_post_copy', 'article_copy', 'video_production', 'image_batch'] as $target) {
            [$item,$branch,$url] = $this->setupTarget($target);
            $saved = $this->saveTarget($target, $item, $branch);
            $first = $this->postJson($url)->assertCreated()->json('data.review');
            $preview = ReviewRequest::findOrFail($first['id'])->preview;
            $text = json_encode($preview, JSON_UNESCAPED_UNICODE);
            foreach (match ($target) {
                'image_post_copy' => ['正式标题', '最终文字', '承接', '画面'],
                'article_copy' => ['标题', '摘要', '正文', '图一', '封面'],
                'video_production' => ['视频说明', '旁白', '推进', '备注', '4'],
                default => ['批次说明', '备注'],
            } as $required) {
                $this->assertStringContainsString($required, $text);
            }
            $saved->update(match ($target) {
                'image_post_copy' => ['final_copy' => '修改页面'],
                'article_copy' => ['body' => '修改正文'],
                'video_production' => ['narration' => '修改旁白'],
                default => ['batch_note' => '修改批次备注'],
            });
            $next = $this->postJson($url)->assertCreated()->json('data.review');
            $this->assertNotSame($first['version_label'], $next['version_label']);
            $this->assertSame($preview, ReviewRequest::findOrFail($first['id'])->preview);
        }
    }

    public function test_image_batch_submission_does_not_touch_asset_usage_or_infer_bindings(): void
    {
        [$item,$branch,$url] = $this->setupTarget('image_batch');
        $this->saveTarget('image_batch', $item, $branch);
        $asset = ReusableAsset::factory()->create(['project_id' => $item->project_id, 'name' => '妈妈', 'last_used_at' => '2024-01-02 03:04:05']);
        ContentPage::factory()->for($item)->create(['page_no' => 1, 'visual_evidence' => '妈妈在客厅']);
        $before = $asset->refresh()->getRawOriginal();
        $this->postJson($url)->assertCreated();
        $this->getJson($url)->assertOk();
        $this->assertSame($before, $asset->refresh()->getRawOriginal());
        $this->assertDatabaseCount('image_batch_unit_settings', 0);
        $this->assertDatabaseCount('image_batch_runs', 0);
    }

    public function test_frozen_title_and_readable_field_order_are_preserved_for_every_target(): void
    {
        foreach (['image_post_copy', 'article_copy', 'video_production', 'image_batch'] as $target) {
            [$item,$branch,$url] = $this->setupTarget($target);
            $this->saveTarget($target, $item, $branch);
            $review = $this->postJson($url)->assertCreated()->json('data.review');
            $item->update(['title' => '后来标题']);
            $preview = ReviewRequest::findOrFail($review['id'])->preview;
            $this->assertSame(['label' => '标题', 'value' => '正式标题'], $preview['items'][0]);
        }
    }

    public function test_video_equivalent_numeric_duration_has_same_identity(): void
    {
        [$item,$branch,$url] = $this->setupTarget('video_production');
        $video = $this->saveTarget('video_production', $item, $branch);
        $first = $this->postJson($url)->assertCreated()->json('data.review.id');
        $units = $video->units;
        $units[0]['duration_seconds'] = '4.0';
        $video->update(['units' => $units]);
        $this->postJson($url)->assertOk()->assertJsonPath('data.review.id', $first);
    }
}
