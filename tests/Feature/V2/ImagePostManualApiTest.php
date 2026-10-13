<?php

namespace Tests\Feature\V2;

use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 小绿书（image_post）正式编辑 API：GET 读取 + PUT 人工保存。
 *
 * GET：返回 item（含 core_message/story_line）/ branch / 按 page_no 升序的 pages。
 * PUT：只允许 title / core_message / story_line / pages[]；变化页面才补版本，
 *      未变化页面不产生无意义版本；fixed_back_cover 受保护（不修改、不新增）。
 * 校验：登录 / 当前 Project / Item 属 Project / Branch 属 Item / Branch format = image_post。
 * 跨 Project → 404；非 image_post → 422；非法 page_type / 重复 page_no → 422。
 */
class ImagePostManualApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    private function imagePostBranch(ContentItem $item): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => 'wechat_official',
            'format' => ContentFormat::ImagePost,
        ]);
    }

    private function pagePayload(int $pageNo, string $type = 'content', array $extra = []): array
    {
        return array_merge([
            'page_no' => $pageNo,
            'page_type' => $type,
            'narrative_role' => 'r',
            'new_fact' => 'f',
            'final_copy' => 'c',
            'visual_evidence' => 'v',
            'continuity' => 'k',
            'production_note' => null,
        ], $extra);
    }

    // ---------- GET ----------

    public function test_show_returns_item_branch_and_sorted_pages(): void
    {
        $item = ContentItem::factory()->create(['core_message' => 'cm', 'story_line' => 'sl']);
        $branch = $this->imagePostBranch($item);
        ContentPage::factory()->for($item)->create(['page_no' => 3, 'page_type' => 'content', 'final_copy' => 'p3']);
        ContentPage::factory()->for($item)->create(['page_no' => 1, 'page_type' => 'cover', 'final_copy' => 'p1']);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post")
            ->assertOk()
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonPath('item.core_message', 'cm')
            ->assertJsonPath('item.story_line', 'sl')
            ->assertJsonPath('branch.channel', 'wechat_official')
            ->assertJsonPath('branch.format', 'image_post')
            ->assertJsonCount(2, 'pages')
            ->assertJsonPath('pages.0.page_no', 1)
            ->assertJsonPath('pages.1.page_no', 3);
    }

    public function test_show_cross_project_returns_404(): void
    {
        $own = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($foreign);
        $this->acting($own);

        $this->getJson("/api/projects/{$foreign->project_id}/items/{$foreign->id}/branches/{$branch->id}/image-post")
            ->assertNotFound();
    }

    public function test_show_non_image_post_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => ContentFormat::Article]);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post")
            ->assertUnprocessable();
    }

    // ---------- PUT ----------

    public function test_put_updates_item_shared_fields(): void
    {
        $item = ContentItem::factory()->create(['title' => 'old', 'core_message' => 'oldc', 'story_line' => 'olds']);
        $branch = $this->imagePostBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'title' => 'new title',
            'core_message' => 'new cm',
            'story_line' => 'new sl',
        ])->assertOk();

        $item->refresh();
        $this->assertSame('new title', $item->title);
        $this->assertSame('new cm', $item->core_message);
        $this->assertSame('new sl', $item->story_line);
    }

    public function test_put_updates_single_page_and_creates_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $page = ContentPage::factory()->for($item)->create([
            'page_no' => 1, 'page_type' => 'content', 'final_copy' => 'old',
        ]);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1, 'content', ['final_copy' => 'new'])],
        ])->assertOk();

        $page->refresh();
        $this->assertSame('new', $page->final_copy);
        $this->assertSame(1, $page->versions()->count());
    }

    public function test_put_adds_new_normal_page(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1, 'cover')],
        ])->assertOk();

        $this->assertDatabaseCount('content_pages', 1);
        $this->assertSame(1, ContentPage::where('page_no', 1)->first()->versions()->count());
    }

    public function test_put_unchanged_page_creates_no_extra_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        ContentPage::factory()->for($item)->create([
            'page_no' => 1, 'page_type' => 'content',
            'narrative_role' => 'r', 'new_fact' => 'f', 'final_copy' => 'c',
            'visual_evidence' => 'v', 'continuity' => 'k', 'production_note' => null,
        ]);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1, 'content')],
        ])->assertOk();

        $this->assertSame(0, ContentPage::first()->versions()->count());
    }

    public function test_put_changed_page_creates_version_and_ignores_absent_pages(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        ContentPage::factory()->for($item)->create(['page_no' => 1, 'page_type' => 'content', 'final_copy' => 'a']);
        $keep = ContentPage::factory()->for($item)->create(['page_no' => 2, 'page_type' => 'content', 'final_copy' => 'b']);
        $this->acting($item);

        // PUT 只给 page 1，page 2 不在 payload —— 不删除、不改动。
        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1, 'content', ['final_copy' => 'a2'])],
        ])->assertOk();

        $this->assertSame(2, ContentPage::count());
        $this->assertSame('a2', ContentPage::where('page_no', 1)->first()->final_copy);
        $this->assertSame('b', $keep->refresh()->final_copy);
        $this->assertSame(1, ContentPage::where('page_no', 1)->first()->versions()->count());
    }

    public function test_put_fixed_back_cover_protected(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $fbc = ContentPage::factory()->for($item)->create([
            'page_no' => 9, 'page_type' => 'fixed_back_cover', 'final_copy' => 'protected',
        ]);
        $this->acting($item);

        $response = $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [
                $this->pagePayload(9, 'fixed_back_cover', ['final_copy' => 'hacked']),
                $this->pagePayload(10, 'fixed_back_cover', ['final_copy' => 'new fbc']),
            ],
        ])->assertOk();

        $response->assertJsonFragment(['code' => 'fixed_back_cover_protected']);
        $fbc->refresh();
        $this->assertSame('protected', $fbc->final_copy); // 未被修改
        $this->assertSame(0, $fbc->versions()->count());
        $this->assertSame(0, ContentPage::where('page_no', 10)->count()); // 不允许新增固定封底
    }

    public function test_put_cross_project_returns_404(): void
    {
        $own = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($foreign);
        $this->acting($own);

        $this->putJson("/api/projects/{$foreign->project_id}/items/{$foreign->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1)],
        ])->assertNotFound();
    }

    public function test_put_non_image_post_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => ContentFormat::Article]);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1)],
        ])->assertUnprocessable();
    }

    public function test_put_invalid_page_type_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1, 'not_a_type')],
        ])->assertUnprocessable();
    }

    public function test_put_duplicate_page_no_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'pages' => [$this->pagePayload(1), $this->pagePayload(1)],
        ])->assertUnprocessable();
    }
    public function test_put_can_clear_optional_item_text_without_changing_title(): void
    {
        $item = ContentItem::factory()->create(['title' => 'Keep title', 'core_message' => 'old', 'story_line' => 'old']);
        $branch = $this->imagePostBranch($item);
        $this->acting($item);
        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", [
            'core_message' => '', 'story_line' => null,
        ])->assertOk();
        $this->assertNull($item->refresh()->core_message);
        $this->assertNull($item->story_line);
        $this->assertSame('Keep title', $item->title);
    }

    public function test_put_rejects_empty_title_when_present(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->acting($item);
        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-post", ['title' => ''])
            ->assertUnprocessable();
    }
}
