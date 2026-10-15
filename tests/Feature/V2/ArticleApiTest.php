<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ArticleContent;
use App\Models\ArticleContentVersion;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RED: ArticleImportController + 四条路由尚未实现，这些测试必须先失败。
 *
 * 契约：
 *   GET    /api/projects/{project}/items/{item}/branches/{branch}/article
 *   PUT    /api/projects/{project}/items/{item}/branches/{branch}/article
 *   POST   /api/projects/{project}/items/{item}/branches/{branch}/article/preview
 *   POST   /api/projects/{project}/items/{item}/branches/{branch}/article/apply
 *
 * 权限：登录；当前 Project；Item 属 Project；Branch 属 Item；Branch format=article；
 *       Branch channel=wechat_official。跨 Project→404；非 article→422；非公众号→422；未登录→401。
 */
class ArticleApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    private function articleBranch(ContentItem $item, array $extra = []): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create(array_merge([
            'channel' => Channel::WechatOfficial,
            'format' => ContentFormat::Article,
        ], $extra));
    }

    private function raw(): string
    {
        return "标题: 青柠新品\n摘要: 摘要文本\n正文:\n这是正文第一段。\n封面建议: 柠檬黄封面\n核心表达: 清爽\n故事线: 果园到餐桌\n配图建议:\nposition: 3, description: 产品图, note: 高清";
    }

    public function test_get_returns_empty_when_no_article_yet(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article")
            ->assertOk()
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonPath('branch.format', 'article')
            ->assertJsonPath('article', null);
    }

    public function test_get_returns_stored_article(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        ArticleContent::factory()->for($item)->for($branch)->create([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B', 'cover_suggestion' => 'C',
            'image_suggestions' => [['position' => 1, 'description' => 'd']],
        ]);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article")
            ->assertOk()
            ->assertJsonPath('article.headline', 'H')
            ->assertJsonPath('article.image_suggestions.0.position', 1);
    }

    public function test_put_saves_article_fields_and_no_image(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article", [
            'headline' => 'H', 'summary' => 'S', 'body' => 'B',
        ])->assertOk();

        $content = ArticleContent::where('content_branch_id', $branch->id)->firstOrFail();
        $this->assertSame('H', $content->headline);
        $this->assertNull($content->image_suggestions);
        $this->assertCount(1, $content->versions);
    }

    public function test_put_saves_image_suggestions(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article", [
            'headline' => 'H',
            'image_suggestions' => [['position' => 2, 'description' => 'x', 'note' => 'n']],
        ])->assertOk();

        $content = ArticleContent::where('content_branch_id', $branch->id)->firstOrFail();
        $this->assertSame(2, $content->image_suggestions[0]['position']);
    }

    public function test_put_changed_creates_version_unchanged_does_not(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        ArticleContent::factory()->for($item)->for($branch)->create([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B', 'cover_suggestion' => null,
            'image_suggestions' => null,
        ]);
        $this->acting($item);

        // 改 headline → 版本 +1
        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article", [
            'headline' => 'H2',
        ])->assertOk();
        $this->assertSame(1, ArticleContentVersion::count());

        // 原样回写 headline → 不再产生版本
        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article", [
            'headline' => 'H2',
        ])->assertOk();
        $this->assertSame(1, ArticleContentVersion::count());
    }

    public function test_put_updates_shared_item_fields(): void
    {
        $item = ContentItem::factory()->create(['core_message' => null, 'story_line' => null]);
        $branch = $this->articleBranch($item);
        $this->acting($item);

        $this->putJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article", [
            'title' => '新标题', 'core_message' => '核心表达', 'story_line' => '故事线',
        ])->assertOk();

        $item->refresh();
        $this->assertSame('新标题', $item->title);
        $this->assertSame('核心表达', $item->core_message);
    }

    public function test_preview_returns_diff_without_writing(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        ArticleContent::factory()->for($item)->for($branch)->create([
            'headline' => 'old', 'summary' => 'same', 'body' => null, 'cover_suggestion' => null,
            'image_suggestions' => null,
        ]);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article/preview", [
            'raw' => $this->raw(),
        ])->assertOk()
            ->assertJsonPath('article.modified.0.field', 'headline');

        // preview 不得落库。
        $this->assertSame('old', ArticleContent::where('content_branch_id', $branch->id)->firstOrFail()->headline);
        $this->assertDatabaseCount('article_content_versions', 0);
    }

    public function test_apply_writes_and_creates_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article/apply", [
            'raw' => $this->raw(),
        ])->assertOk();

        $content = ArticleContent::where('content_branch_id', $branch->id)->firstOrFail();
        $this->assertSame('青柠新品', $content->headline);
        $this->assertCount(1, $content->versions);
    }

    public function test_invalid_ai_structure_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);
        $this->acting($item);

        $this->postJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article/preview", [
            'raw' => '完全无法识别的闲聊文本',
        ])->assertUnprocessable();
    }

    public function test_cross_project_returns_404(): void
    {
        $own = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $branch = $this->articleBranch($foreign);
        $this->acting($own);

        $this->getJson("/api/projects/{$foreign->project_id}/items/{$foreign->id}/branches/{$branch->id}/article")
            ->assertNotFound();
    }

    public function test_non_article_branch_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial,
            'format' => ContentFormat::ImagePost,
        ]);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article")
            ->assertUnprocessable();
    }

    public function test_non_wechat_channel_returns_422(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatChannels,
            'format' => ContentFormat::Article,
        ]);
        $this->acting($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article")
            ->assertUnprocessable();
    }

    public function test_authentication_required_returns_401(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->articleBranch($item);

        $this->getJson("/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/article")
            ->assertUnauthorized();
    }
}
