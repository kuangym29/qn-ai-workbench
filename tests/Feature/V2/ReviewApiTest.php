<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 轻量审核中心正式 API（B03）。
 *
 * GET  /api/projects/{project}/reviews                      默认 pending
 * GET  /api/projects/{project}/reviews/{review}             详情
 * POST /api/projects/{project}/reviews/{review}/decision    决策（pending 才可；已决策 → 409）
 *
 * 权限：登录 + 当前 Project(session) + Review 属 Project（跨 Project / 不存在 → 404）。
 * 覆盖任务规定的 1-21 项（重复 pending 防护 / 新版本在 Unit 层验证）。
 */
class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(Project $project): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $project->id]);
    }

    private function branch(Project $project, string $channel = 'wechat_official', string $format = 'image_post'): ContentBranch
    {
        $item = ContentItem::factory()->for($project)->create();

        return ContentBranch::factory()->for($item)->create(['channel' => $channel, 'format' => $format]);
    }

    private function makeReview(Project $project, ContentBranch $branch, array $attrs = []): ReviewRequest
    {
        return ReviewRequest::factory()->create(array_merge([
            'project_id' => $project->id,
            'content_item_id' => $branch->content_item_id,
            'content_branch_id' => $branch->id,
        ], $attrs));
    }

    // ---------- 列表 ----------

    public function test_list_returns_pending_by_default(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $this->makeReview($project, $branch);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_list_empty(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_default_only_pending(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $this->makeReview($project, $branch, ['status' => 'pending']);
        $this->makeReview($project, $branch, ['status' => 'approved']);
        $this->makeReview($project, $branch, ['status' => 'changes_requested']);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    // ---------- 详情 ----------

    public function test_detail_returns_full(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch, [
            'preview_summary' => '摘要',
            'preview' => ['kind' => 'items', 'items' => [['label' => '正文', 'value' => 'x']]],
        ]);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $review->id)
            ->assertJsonPath('data.preview_summary', '摘要')
            ->assertJsonPath('data.preview.kind', 'items');
    }

    // ---------- 决策：通过 ----------

    public function test_approved_via_api(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.review_id', $review->id)
            ->assertJsonPath('data.decision', 'approved')
            ->assertJsonPath('data.comment', null)
            ->assertJsonPath('data.target_label', null)
            ->assertJsonPath('data.decided_at', fn ($v) => $v !== null);

        $this->assertSame('approved', $review->refresh()->status->value);
    }

    public function test_changes_requested_via_api(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", [
            'decision' => 'changes_requested',
            'comment' => '正文再口语一点',
            'target_label' => 'P4',
        ])->assertOk()
            ->assertJsonPath('data.decision', 'changes_requested')
            ->assertJsonPath('data.comment', '正文再口语一点')
            ->assertJsonPath('data.target_label', 'P4');

        $this->assertSame('changes_requested', $review->refresh()->status->value);
    }

    public function test_changes_requested_comment_required(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", [
            'decision' => 'changes_requested',
        ])->assertUnprocessable();
    }

    // ---------- 决策：规则 ----------

    public function test_target_label_in_detail(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch, ['review_target_label' => '公众号图文']);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.review_target_label', '公众号图文');
    }

    public function test_append_only_decision_blocks_repeat_and_keeps_single_record(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved'])
            ->assertOk();

        // 重复决策：409，且不追加第二条（append-only，不覆盖）。
        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved'])
            ->assertConflict();

        $this->assertSame(1, $review->decisions()->count());
    }

    public function test_decision_no_starts_at_one(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved'])
            ->assertOk();

        $this->assertSame(1, $review->decisions()->first()->decision_no);
    }

    public function test_request_status_updated_after_decision(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved']);
        $this->assertSame('approved', $review->refresh()->status->value);

        $review2 = $this->makeReview($project, $branch);
        $this->postJson("/api/projects/{$project->id}/reviews/{$review2->id}/decision", [
            'decision' => 'changes_requested',
            'comment' => '改',
        ]);
        $this->assertSame('changes_requested', $review2->refresh()->status->value);
    }

    public function test_decided_at_set_after_decision(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved'])
            ->assertOk();

        $this->assertNotNull($review->refresh()->decided_at);
    }

    public function test_duplicate_decision_returns_409(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->postJson("/api/projects/{$project->id}/reviews/{$review->id}/decision", [
            'decision' => 'changes_requested',
            'comment' => '再改',
        ])->assertConflict();
    }

    // ---------- 权限 ----------

    public function test_cross_project_review_returns_404(): void
    {
        $own = Project::factory()->create();
        $foreign = Project::factory()->create();
        $foreignBranch = $this->branch($foreign);
        $foreignReview = $this->makeReview($foreign, $foreignBranch);
        $this->acting($own);

        // 用 own 的 project 上下文访问 foreign 的 review → 404（不返回）。
        $this->getJson("/api/projects/{$own->id}/reviews/{$foreignReview->id}")
            ->assertNotFound();
    }

    public function test_not_found_review_returns_404(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/99999")
            ->assertNotFound();
    }

    // ---------- Channel / Format 正式 enum ----------

    public function test_channel_formal_enum(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project, Channel::Xiaohongshu->value, ContentFormat::ImagePost->value);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.channel', 'xiaohongshu')
            ->assertJsonPath('data.format', 'image_post');
    }

    public function test_format_formal_enum(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project, Channel::WechatOfficial->value, ContentFormat::Article->value);
        $review = $this->makeReview($project, $branch);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.channel', 'wechat_official')
            ->assertJsonPath('data.format', 'article');
    }

    // ---------- review_target_label 后端提供 ----------

    public function test_review_target_label_provided_by_backend(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch, [
            'review_target_type' => 'video_production',
            'review_target_label' => '视频制作',
        ]);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.review_target_type', 'video_production')
            ->assertJsonPath('data.review_target_label', '视频制作');
    }

    // ---------- preview 三种 kind ----------

    public function test_preview_text(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch, [
            'preview' => ['kind' => 'text', 'text' => '可读摘要文本'],
        ]);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.preview.kind', 'text')
            ->assertJsonPath('data.preview.text', '可读摘要文本');
    }

    public function test_preview_lines(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch, [
            'preview' => ['kind' => 'lines', 'lines' => ['行一', '行二']],
        ]);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.preview.kind', 'lines')
            ->assertJsonPath('data.preview.lines', ['行一', '行二']);
    }

    public function test_preview_items(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);
        $review = $this->makeReview($project, $branch, [
            'preview' => ['kind' => 'items', 'items' => [['label' => '封面建议', 'value' => '暖色调']]],
        ]);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.preview.kind', 'items')
            ->assertJsonPath('data.preview.items.0.label', '封面建议')
            ->assertJsonPath('data.preview.items.0.value', '暖色调');
    }
}
