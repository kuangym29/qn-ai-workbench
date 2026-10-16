<?php

namespace Tests\Feature\V2;

use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RED: controller + routes do not exist yet — these must fail until GREEN.
 *
 * API 契约：
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/image-post/preview
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/image-post/apply
 * preview 不落库；apply 落库并保留版本；非 image_post 分支 → 422；
 * 跨 Project → 404；结构非法 → 422；未登录 → 401。
 */
class ImagePostImportApiTest extends TestCase
{
    use RefreshDatabase;

    private function raw(string $pageType = 'content', int $pageNo = 1, string $copy = 'x'): string
    {
        return <<<RAW
--- 第{$pageNo}页 ---
页码: {$pageNo}
页面类型: {$pageType}
叙事职责: r
本页新事实: f
最终文字: {$copy}
画面证据: v
前后页承接: c
RAW;
    }

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    public function test_preview_returns_diff_and_does_not_write(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::ImagePost]);
        ContentPage::factory()->for($item)->create([
            'page_no' => 1, 'page_type' => 'content',
            'narrative_role' => 'old', 'new_fact' => null, 'final_copy' => 'old',
            'visual_evidence' => null, 'continuity' => null, 'production_note' => null,
        ]);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/preview", [
            'raw' => $this->raw('content', 1, 'new'),
        ])->assertOk()
            ->assertJsonCount(1, 'pages.modified')
            ->assertJsonCount(0, 'pages.added');

        // 仍不得落库。
        $this->assertSame('old', ContentPage::first()->refresh()->final_copy);
        $this->assertDatabaseCount('content_page_versions', 0);
    }

    public function test_apply_writes_pages_and_versions(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::ImagePost]);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/apply", [
            'raw' => $this->raw('content', 1, 'written'),
        ])->assertOk();

        $this->assertDatabaseCount('content_pages', 1);
        $this->assertSame('written', ContentPage::first()->final_copy);
        $this->assertSame(1, ContentPage::first()->versions()->count());
    }

    public function test_partial_apply_only_selected_pages(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::ImagePost]);
        $this->acting($item);

        $raw = $this->raw('content', 1, 'one')."\n\n--- 第2页 ---\n页码: 2\n页面类型: content\n叙事职责: r\n本页新事实: f\n最终文字: two\n画面证据: v\n前后页承接: c\n";
        // 仅确认第 1 页。
        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/apply", [
            'raw' => $raw, 'page_nos' => [1],
        ])->assertOk();

        $this->assertDatabaseCount('content_pages', 1);
        $this->assertSame(1, ContentPage::where('page_no', 1)->count());
        $this->assertSame(0, ContentPage::where('page_no', 2)->count());
    }

    public function test_non_image_post_branch_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::Article]);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/preview", [
            'raw' => $this->raw(),
        ])->assertUnprocessable();
    }

    public function test_cross_project_returns_404(): void
    {
        $own = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($foreign)->create(['format' => ContentFormat::ImagePost]);
        // 以 own 项目为当前会话，却访问 foreign 项目的分支。
        $this->acting($own);

        $this->postJson("/api/projects/{$foreign->project_id}/items/{$foreign->id}/branches/{$branch->id}/image-post/preview", [
            'raw' => $this->raw(),
        ])->assertNotFound();
    }

    public function test_invalid_structure_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::ImagePost]);
        $this->acting($item);

        // 缺页码 → 结构性错误。
        $raw = "--- 第1页 ---\n页面类型: content\n叙事职责: r\n本页新事实: f\n最终文字: x\n画面证据: v\n前后页承接: c\n";
        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/preview", [
            'raw' => $raw,
        ])->assertUnprocessable();
    }

    public function test_authentication_required_returns_401(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::ImagePost]);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/preview", [
            'raw' => $this->raw(),
        ])->assertUnauthorized();
    }
    public function test_reapplying_unchanged_import_does_not_add_versions(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['format' => ContentFormat::ImagePost]);
        $this->acting($item);
        $url = "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post/apply";
        $this->postJson($url, ['raw' => $this->raw()])->assertOk();
        $this->postJson($url, ['raw' => $this->raw()])->assertOk();
        $this->assertDatabaseCount('content_page_versions', 1);
    }
}
