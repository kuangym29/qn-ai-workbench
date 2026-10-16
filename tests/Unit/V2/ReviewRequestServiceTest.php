<?php

namespace Tests\Unit\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Services\V2\ReviewRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ReviewRequestService：创建（含重复 pending 防护）+ 读取。
 *
 * 关键不变量：
 *   - 同 (content_branch_id, review_target_type, version_label) 已存在 pending → 不重复创建第二条。
 *   - 新 version_label 总是允许新建。
 *   - 创建即 pending + submitted_at。
 */
class ReviewRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function branch(Project $project, string $channel = 'wechat_official', string $format = 'image_post'): ContentBranch
    {
        $item = ContentItem::factory()->for($project)->create();

        return ContentBranch::factory()->for($item)->create([
            'channel' => $channel,
            'format' => $format,
        ]);
    }

    public function test_create_stores_fields_and_sets_pending_and_submitted_at(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);

        $request = (new ReviewRequestService())->create($project, $branch, [
            'review_target_type' => 'image_post_copy',
            'version_label' => 'v1',
            'preview_summary' => '摘要',
            'preview' => ['kind' => 'items', 'items' => [['label' => '正文', 'value' => 'x']]],
        ]);

        $this->assertDatabaseCount('review_requests', 1);
        $this->assertSame(ReviewStatus::Pending, $request->status);
        $this->assertNotNull($request->submitted_at);
        $this->assertSame($project->id, $request->project_id);
        $this->assertSame($branch->content_item_id, $request->content_item_id);
        $this->assertSame($branch->id, $request->content_branch_id);
        // label 由后端根据 type 提供，前端不猜。
        $this->assertSame('图片文章', $request->review_target_label);
        $this->assertSame(ReviewTargetType::ImagePostCopy, $request->review_target_type);
        $this->assertSame(['kind' => 'items', 'items' => [['label' => '正文', 'value' => 'x']]], $request->preview);
    }

    public function test_duplicate_pending_returns_existing_without_creating_second(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);

        $first = (new ReviewRequestService())->create($project, $branch, [
            'review_target_type' => 'image_post_copy',
            'version_label' => 'v1',
        ]);
        $second = (new ReviewRequestService())->create($project, $branch, [
            'review_target_type' => 'image_post_copy',
            'version_label' => 'v1',
        ]);

        // 不得重复创建第二条 pending：仍是同一条。
        $this->assertSame(1, ReviewRequest::count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(ReviewStatus::Pending, $second->status);
    }

    public function test_new_version_label_allows_new_request(): void
    {
        $project = Project::factory()->create();
        $branch = $this->branch($project);

        (new ReviewRequestService())->create($project, $branch, [
            'review_target_type' => 'image_post_copy',
            'version_label' => 'v1',
        ]);
        (new ReviewRequestService())->create($project, $branch, [
            'review_target_type' => 'image_post_copy',
            'version_label' => 'v2',
        ]);

        // 新版本允许产生新审核请求。
        $this->assertSame(2, ReviewRequest::count());
    }

    public function test_create_rejects_branch_not_belonging_to_project(): void
    {
        $own = Project::factory()->create();
        $foreign = Project::factory()->create();
        $foreignBranch = $this->branch($foreign);

        $this->expectException(InvalidArgumentException::class);
        (new ReviewRequestService())->create($own, $foreignBranch, [
            'review_target_type' => 'image_post_copy',
            'version_label' => 'v1',
        ]);
    }
}
