<?php

namespace Tests\Feature\V2;

use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\PublicationRecord;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\V2\ContentVersionResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema 回归：publication_records.review_request_id 合同。
 *
 * 合同：发布记录必须始终引用触发本次发布的那条 exact approved ReviewRequest。
 * 因此列 NOT NULL，且删除被引用的 ReviewRequest 由数据库 RESTRICT 拒绝，
 * 不得静默把历史发布记录的审核凭证置 NULL。
 */
class PublicationSchemaRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 准备一个已保存正式内容的 article 分支，返回 [item, branch, url]。
     *
     * @return array{0: ContentItem, 1: ContentBranch, 2: string}
     */
    private function setupApprovedTarget(string $target = 'article_copy'): array
    {
        $item = ContentItem::factory()->create(['title' => '正式标题']);
        $branch = ContentBranch::factory()->for($item)->create([
            'format' => match ($target) {
                'article_copy' => 'article', 'video_production' => 'video', default => 'image_post'
            },
            'channel' => $target === 'video_production' ? 'wechat_channels' : 'wechat_official',
        ]);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);

        $scope = ['project_id' => $item->project_id, 'content_item_id' => $item->id, 'content_branch_id' => $branch->id];
        if ($target === 'article_copy') {
            ArticleContent::create([...$scope, 'headline' => '标题', 'summary' => '摘要', 'body' => '正文', 'image_suggestions' => [['position' => '开头', 'description' => '图一']], 'cover_suggestion' => '封面']);
        }

        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/publication/{$target}";

        return [$item, $branch, $url];
    }

    /**
     * 直接落地一条与当前 exact version 匹配的 approved ReviewRequest（不产生 review_decisions 子行，
     * 保证后续 RESTRICT 断言唯一来自 publication_records.review_request_id 外键）。
     */
    private function seedApprovedReview(ContentItem $item, ContentBranch $branch, string $target): ReviewRequest
    {
        $type = ReviewTargetType::from($target);
        $versionLabel = app(ContentVersionResolver::class)->versionLabel($item, $branch, $type);

        return ReviewRequest::create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'content_branch_id' => $branch->id,
            'review_target_type' => $type,
            'review_target_label' => $type->label(),
            'version_label' => $versionLabel,
            'preview_summary' => 'schema 回归：exact approved review',
            'preview' => [],
            'status' => ReviewStatus::Approved,
            'submitted_at' => now(),
            'decided_at' => now(),
        ]);
    }

    public function test_publication_record_holds_non_null_review_request_reference(): void
    {
        [$item, $branch, $url] = $this->setupApprovedTarget();
        $review = $this->seedApprovedReview($item, $branch, 'article_copy');

        $this->postJson($url)->assertCreated();

        $record = PublicationRecord::query()->firstOrFail();
        // 断言点 1：成功创建后 review_request_id 非 null。
        $this->assertNotNull($record->review_request_id, '发布记录必须持有审核凭证引用');
        $this->assertNotSame(0, $record->review_request_id);

        // 断言点 2：确实引用用于当前 exact version 的那条 approved ReviewRequest。
        $this->assertSame($review->id, $record->review_request_id);
        $this->assertSame($review->version_label, $record->version_label);
        $referenced = ReviewRequest::query()->find($record->review_request_id);
        $this->assertNotNull($referenced);
        $this->assertSame(ReviewStatus::Approved, $referenced->status);
        $this->assertSame($item->project_id, $referenced->project_id);
        $this->assertSame($branch->id, $referenced->content_branch_id);
        $this->assertSame('article_copy', $referenced->review_target_type->value);
    }

    public function test_publication_record_review_request_id_cannot_be_null(): void
    {
        [$item, $branch] = $this->setupApprovedTarget();
        $review = $this->seedApprovedReview($item, $branch, 'article_copy');

        // 断言点 3：绕过模型层直接向 schema 插入 null 审核凭证，必须被数据库拒绝。
        $this->expectException(QueryException::class);
        try {
            DB::table('publication_records')->insert([
                'project_id' => $item->project_id,
                'content_item_id' => $item->id,
                'content_branch_id' => $branch->id,
                'review_request_id' => null,
                'review_target_type' => 'article_copy',
                'version_label' => '手工构造-null-凭证',
                'published_by_user_id' => null,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $this->assertSame(0, PublicationRecord::query()->count());
            $this->assertNotNull(ReviewRequest::query()->find($review->id));
        }
    }

    public function test_deleting_referenced_review_request_cannot_null_publication_credential(): void
    {
        [$item, $branch, $url] = $this->setupApprovedTarget();
        $review = $this->seedApprovedReview($item, $branch, 'article_copy');
        $this->postJson($url)->assertCreated();

        $record = PublicationRecord::query()->firstOrFail();
        $recordId = $record->id;
        $reviewId = $record->review_request_id;
        $versionLabel = $record->version_label;
        $publishedAt = $record->published_at;

        // 断言点 4：删除被引用的 ReviewRequest 必须被 RESTRICT 拒绝（而非 nullOnDelete 静默清空）。
        $deleteRejected = false;
        try {
            $review->delete();
        } catch (QueryException $e) {
            $deleteRejected = true;
        }
        $this->assertTrue($deleteRejected, '删除被 publication_records 引用的 ReviewRequest 必须被数据库拒绝');

        // 审核凭证未被清空：记录仍在，review_request_id 保持原值。
        $record->refresh();
        $this->assertSame($recordId, $record->id);
        $this->assertNotNull($record->review_request_id);
        $this->assertSame($reviewId, $record->review_request_id);
        $this->assertSame($versionLabel, $record->version_label);
        $this->assertEquals($publishedAt, $record->published_at);
        $this->assertTrue(ReviewRequest::query()->whereKey($reviewId)->exists(), '被引用的 ReviewRequest 不得被删除');
    }
}
