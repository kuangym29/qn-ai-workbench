<?php

namespace Tests\Feature\V2;

use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ImageBatchDraft;
use App\Models\PublicationRecord;
use App\Models\ReusableAsset;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicationApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupTarget(string $target): array
    {
        $item = ContentItem::factory()->create(['title' => '正式标题']);
        $branch = ContentBranch::factory()->for($item)->create([
            'format' => match ($target) {
                'article_copy' => 'article', 'video_production' => 'video', default => 'image_post'
            },
            'channel' => $target === 'video_production' ? 'wechat_channels' : 'wechat_official',
        ]);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/publication/{$target}";

        return [$item, $branch, $url];
    }

    private function saveTarget(string $target, ContentItem $item, ContentBranch $branch): mixed
    {
        $scope = ['project_id' => $item->project_id, 'content_item_id' => $item->id, 'content_branch_id' => $branch->id];

        return match ($target) {
            'article_copy' => ArticleContent::create([...$scope, 'headline' => '标题', 'summary' => '摘要', 'body' => '正文', 'image_suggestions' => [['position' => '开头', 'description' => '图一']], 'cover_suggestion' => '封面']),
            'video_production' => VideoContent::create([...$scope, 'batch_instruction' => '视频说明', 'narration' => '旁白', 'units' => [['source_page_id' => 7, 'dynamic_instruction' => '推进', 'duration_seconds' => 4, 'note' => '备注']]]),
            default => ContentPage::factory()->for($item)->create(['page_no' => 1, 'final_copy' => '最终文字', 'narrative_role' => '承接', 'visual_evidence' => '画面']),
        };
    }

    private function publishUrl(string $target, ContentItem $item, int $branchId): string
    {
        return "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branchId}/publication/{$target}";
    }

    private function submitReview(ContentItem $item, int $branchId, string $target): int
    {
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branchId}/review-submissions/{$target}";

        return $this->postJson($url)->assertCreated()->json('data.review.id');
    }

    private function approveReview(ContentItem $item, int $reviewId): void
    {
        $this->postJson("/api/projects/{$item->project_id}/reviews/{$reviewId}/decision", ['decision' => 'approved', 'comment' => '通过'])->assertOk();
    }

    public function test_get_state_before_approval_is_not_publishable(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $state = $this->getJson($url)->assertOk()->json('data');
        $this->assertFalse($state['approved']);
        $this->assertFalse($state['published']);
        $this->assertFalse($state['can_publish']);
        $this->assertArrayHasKey('current_version_label', $state);
    }

    public function test_publish_without_review_is_422(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $this->postJson($url)->assertUnprocessable()->assertJsonPath('message', '当前保存版本尚未审核通过，请先提交审核并通过后再发布。');
        $this->assertDatabaseCount('publication_records', 0);
    }

    public function test_pending_review_publish_is_422(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $this->submitReview($item, $branch->id, 'article_copy'); // pending
        $this->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('publication_records', 0);
    }

    public function test_changes_requested_publish_is_422(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $reviewId = $this->submitReview($item, $branch->id, 'article_copy');
        $this->postJson("/api/projects/{$item->project_id}/reviews/{$reviewId}/decision", ['decision' => 'changes_requested', 'comment' => '改'])->assertOk();
        $this->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('publication_records', 0);
    }

    public function test_publish_exact_approved_version_creates(): void
    {
        foreach (['image_post_copy', 'article_copy', 'video_production'] as $target) {
            [$item, $branch, $url] = $this->setupTarget($target);
            $this->saveTarget($target, $item, $branch);
            $reviewId = $this->submitReview($item, $branch->id, $target);
            $this->approveReview($item, $reviewId);

            $state = $this->getJson($url)->assertOk()->json('data');
            $this->assertTrue($state['approved']);
            $this->assertFalse($state['published']);
            $this->assertTrue($state['can_publish']);

            $response = $this->postJson($url)->assertCreated()->assertJsonPath('data.created', true);
            $id = $response->json('data.publication.id');
            $this->assertNotNull($response->json('data.publication.published_at'));

            $after = $this->getJson($url)->assertOk()->json('data');
            $this->assertTrue($after['published']);
            $this->assertFalse($after['can_publish']);
            $this->assertSame($id, $after['publication_id']);
        }
    }

    public function test_publish_idempotent_returns_same_record(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $reviewId = $this->submitReview($item, $branch->id, 'article_copy');
        $this->approveReview($item, $reviewId);

        $first = $this->postJson($url)->assertCreated()->json('data.publication');
        $second = $this->postJson($url)->assertOk()->assertJsonPath('data.created', false)->json('data.publication');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['published_at'], $second['published_at']);
        $this->assertDatabaseCount('publication_records', 1);
    }

    public function test_content_change_after_publish_blocks_new_version_publish(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $article = $this->saveTarget('article_copy', $item, $branch);
        $reviewId = $this->submitReview($item, $branch->id, 'article_copy');
        $this->approveReview($item, $reviewId);
        $this->postJson($url)->assertCreated();

        // 修改正式内容 → 产生版本 B（未审核）。
        $article->update(['body' => '修改后的正文']);
        $this->postJson($url)->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.approved', false)->assertJsonPath('data.published', false);

        // 重新审核通过 B → 可以发布 B，A 的发布记录不变。
        $reviewB = $this->submitReview($item, $branch->id, 'article_copy');
        $this->approveReview($item, $reviewB);
        $this->postJson($url)->assertCreated();
        $this->assertDatabaseCount('publication_records', 2);
        $this->assertSame(1, PublicationRecord::where('version_label', $first = ReviewRequest::find($reviewId)->version_label)->count());
    }

    public function test_cross_project_is_404(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $foreign = ContentItem::factory()->create();
        $this->withSession(['current_project_id' => $foreign->project_id])->getJson($url)->assertNotFound();
        $this->withSession(['current_project_id' => $foreign->project_id])->postJson($url)->assertNotFound();
    }

    public function test_item_branch_mismatch_is_404(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $foreignItem = ContentItem::factory()->create();
        $this->getJson(str_replace("/items/{$item->id}/", "/items/{$foreignItem->id}/", $url))->assertNotFound();
        $foreignBranch = ContentBranch::factory()->create();
        $this->postJson(str_replace("/branches/{$branch->id}/", "/branches/{$foreignBranch->id}/", $url))->assertNotFound();
    }

    public function test_target_mismatch_is_422(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $this->getJson(str_replace('article_copy', 'image_post_copy', $url))->assertUnprocessable();
        $this->postJson(str_replace('article_copy', 'invalid', $url))->assertUnprocessable();
    }

    public function test_image_batch_publish_is_422(): void
    {
        [$item, $branch, $url] = $this->setupTarget('image_batch');
        ContentPage::factory()->for($item)->create(['page_no' => 1]);
        ImageBatchDraft::create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'content_branch_id' => $branch->id, 'batch_instruction' => '批次说明', 'batch_note' => '备注']);
        $this->getJson($url)->assertUnprocessable();
        $this->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('publication_records', 0);
    }

    public function test_forged_fields_are_422(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        foreach (['review_request_id', 'version_label', 'snapshot', 'content', 'published_at', 'published_by_user_id'] as $field) {
            $this->postJson($url, [$field => 'forged'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('publication_records', 0);
    }

    public function test_publication_record_is_append_only(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $reviewId = $this->submitReview($item, $branch->id, 'article_copy');
        $this->approveReview($item, $reviewId);
        $id = $this->postJson($url)->assertCreated()->json('data.publication.id');

        $record = PublicationRecord::findOrFail($id);
        $threwUpdate = false;
        try {
            $record->update(['version_label' => 'tampered']);
        } catch (\Throwable) {
            $threwUpdate = true;
        }
        $this->assertTrue($threwUpdate, 'PublicationRecord must reject UPDATE.');

        $threwDelete = false;
        try {
            $record->delete();
        } catch (\Throwable) {
            $threwDelete = true;
        }
        $this->assertTrue($threwDelete, 'PublicationRecord must reject DELETE.');
        $this->assertDatabaseHas('publication_records', ['id' => $id]);
    }

    public function test_publication_has_no_review_or_asset_side_effects(): void
    {
        [$item, $branch, $url] = $this->setupTarget('article_copy');
        $this->saveTarget('article_copy', $item, $branch);
        $reviewId = $this->submitReview($item, $branch->id, 'article_copy');
        $this->approveReview($item, $reviewId);

        $asset = ReusableAsset::factory()->create(['project_id' => $item->project_id, 'name' => '妈妈', 'last_used_at' => '2024-01-02 03:04:05']);
        $reviewStatusBefore = ReviewRequest::find($reviewId)->status->value;
        $decisionsBefore = \App\Models\ReviewDecision::count();
        $assetBefore = $asset->refresh()->getRawOriginal();

        $this->postJson($url)->assertCreated();

        $this->assertSame($reviewStatusBefore, ReviewRequest::find($reviewId)->status->value, 'ReviewRequest status must not change.');
        $this->assertSame($decisionsBefore, \App\Models\ReviewDecision::count(), 'No ReviewDecision must be created.');
        $this->assertSame($assetBefore, $asset->refresh()->getRawOriginal(), 'ReusableAsset.last_used_at must not change.');
        $this->assertDatabaseCount('image_batch_runs', 0);
    }

    public function test_image_post_label_is_图片文章_not_小绿书内容(): void
    {
        [$item, $branch, $url] = $this->setupTarget('image_post_copy');
        ContentPage::factory()->for($item)->create(['page_no' => 1, 'final_copy' => '最终文字', 'narrative_role' => '承接', 'visual_evidence' => '画面']);
        $reviewId = $this->submitReview($item, $branch->id, 'image_post_copy');
        $review = ReviewRequest::findOrFail($reviewId);
        $this->assertSame('图片文章', $review->review_target_label);
        $this->assertStringContainsString('图片文章', $review->preview_summary);
        $this->assertStringNotContainsString('小绿书内容', $review->preview_summary);

        $this->approveReview($item, $reviewId);
        $state = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame('image_post_copy', $state['target_type']);
        $this->postJson($url)->assertCreated();
    }
}
