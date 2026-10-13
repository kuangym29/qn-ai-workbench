<?php

namespace Tests\Unit\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReviewDecision as ReviewDecisionEnum;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ReviewDecision;
use App\Models\ReviewRequest;
use App\Services\V2\ReviewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ReviewPresenter：对齐 doubao/v2-review-center-frontend 的合同。
 *
 * ReviewItem / ReviewDetail / Preview / DecisionResult。
 * channel / format 来自关联分支；review_target_label 由后端提供；preview 透传 {kind,text|lines|items}。
 */
class ReviewPresenterTest extends TestCase
{
    use RefreshDatabase;

    private function branch(Project $project, string $channel, string $format): ContentBranch
    {
        $item = ContentItem::factory()->for($project)->create(['title' => '我的内容']);

        return ContentBranch::factory()->for($item)->create(['channel' => $channel, 'format' => $format]);
    }

    public function test_to_item_includes_branch_channel_format_and_backend_label(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project, Channel::Xiaohongshu->value, ContentFormat::Article->value);

        $request = ReviewRequest::factory()->create([
            'project_id' => $project->id,
            'content_item_id' => $branch->content_item_id,
            'content_branch_id' => $branch->id,
            'review_target_type' => 'article_copy',
            'review_target_label' => '公众号图文',
        ]);

        $item = (new ReviewPresenter())->toItem($request);

        $this->assertSame($request->id, $item['id']);
        $this->assertSame($branch->content_item_id, $item['content_item_id']);
        $this->assertSame($branch->id, $item['branch_id']);
        $this->assertSame('pending', $item['status']);
        $this->assertSame('我的内容', $item['content_title']);
        // 正式 enum，无别名。
        $this->assertSame('xiaohongshu', $item['channel']);
        $this->assertSame('article', $item['format']);
        $this->assertSame('article_copy', $item['review_target_type']);
        $this->assertSame('公众号图文', $item['review_target_label']);
        $this->assertSame('v1', $item['version_label']);
    }

    public function test_to_detail_includes_preview(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project, Channel::WechatOfficial->value, ContentFormat::ImagePost->value);

        $request = ReviewRequest::factory()->create([
            'project_id' => $project->id,
            'content_item_id' => $branch->content_item_id,
            'content_branch_id' => $branch->id,
            'preview' => ['kind' => 'items', 'items' => [['label' => '封面', 'value' => '图']]],
        ]);

        $detail = (new ReviewPresenter())->toDetail($request);

        $this->assertSame(['kind' => 'items', 'items' => [['label' => '封面', 'value' => '图']]], $detail['preview']);
        $this->assertArrayHasKey('preview_summary', $detail);
    }

    public function test_preview_text_passthrough(): void
    {
        $request = ReviewRequest::factory()->create(['preview' => ['kind' => 'text', 'text' => '一段可读摘要']]);

        $this->assertSame(
            ['kind' => 'text', 'text' => '一段可读摘要'],
            (new ReviewPresenter())->toDetail($request)['preview']
        );
    }

    public function test_preview_lines_passthrough(): void
    {
        $request = ReviewRequest::factory()->create([
            'preview' => ['kind' => 'lines', 'lines' => ['第一行', '第二行']],
        ]);

        $this->assertSame(
            ['kind' => 'lines', 'lines' => ['第一行', '第二行']],
            (new ReviewPresenter())->toDetail($request)['preview']
        );
    }

    public function test_preview_items_passthrough(): void
    {
        $request = ReviewRequest::factory()->create([
            'preview' => ['kind' => 'items', 'items' => [['label' => 'P4', 'value' => '片段文案']]],
        ]);

        $this->assertSame(
            ['kind' => 'items', 'items' => [['label' => 'P4', 'value' => '片段文案']]],
            (new ReviewPresenter())->toDetail($request)['preview']
        );
    }

    public function test_to_decision_result_shape(): void
    {
        $request = ReviewRequest::factory()->create();
        $decision = ReviewDecision::factory()->for($request)->create([
            'decision_no' => 1,
            'decision' => ReviewDecisionEnum::ChangesRequested,
            'comment' => '改这里',
            'target_label' => 'P4',
        ]);

        $result = (new ReviewPresenter())->toDecisionResult($decision);

        $this->assertSame($request->id, $result['review_id']);
        $this->assertSame('changes_requested', $result['decision']);
        $this->assertSame('改这里', $result['comment']);
        $this->assertSame('P4', $result['target_label']);
        $this->assertNotNull($result['decided_at']);
    }
}
