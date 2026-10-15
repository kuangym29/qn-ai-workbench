<?php

namespace Tests\Unit\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ArticleContent;
use App\Models\ArticleContentVersion;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Services\V2\ArticleImportParser;
use App\Services\V2\ArticleImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RED: ArticleImportService 尚未实现，这些测试必须先失败。
 *
 * 覆盖：preview 只读、首导入=added、再导入=modified/unchanged、image_suggestions 整体 Diff、
 * apply 落库并仅在变化时创建版本、完全 unchanged 不建无意义版本。
 */
class ArticleImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function branch(ContentItem $item): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial,
            'format' => ContentFormat::Article,
        ]);
    }

    private function parsed(array $article = [], array $item = []): array
    {
        return ['item' => $item, 'article' => $article];
    }

    public function test_preview_first_import_marks_all_as_added(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);

        $result = (new ArticleImportService())->preview($branch, $this->parsed([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B', 'cover_suggestion' => 'C',
            'image_suggestions' => [['position' => 1, 'description' => 'd']],
        ]));

        $this->assertCount(5, $result['article']['added']);
        $this->assertCount(0, $result['article']['modified']);
        $this->assertCount(0, $result['article']['unchanged']);
    }

    public function test_preview_existing_shows_modified_and_unchanged(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        ArticleContent::factory()->for($item)->for($branch)->create([
            'headline' => 'old', 'summary' => 'same', 'body' => null, 'cover_suggestion' => null,
            'image_suggestions' => null,
        ]);

        $result = (new ArticleImportService())->preview($branch, $this->parsed([
            'headline' => 'new', 'summary' => 'same', 'body' => 'filled',
        ]));

        $modifiedFields = array_column($result['article']['modified'], 'field');
        $this->assertContains('headline', $modifiedFields);
        $this->assertContains('body', $modifiedFields);
        $this->assertContains('summary', array_column($result['article']['unchanged'], 'field'));
    }

    public function test_preview_unchanged_creates_no_added_or_modified(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        ArticleContent::factory()->for($item)->for($branch)->create([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B', 'cover_suggestion' => 'C',
            'image_suggestions' => [['position' => 1, 'description' => 'd']],
        ]);

        $result = (new ArticleImportService())->preview($branch, $this->parsed([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B', 'cover_suggestion' => 'C',
            'image_suggestions' => [['position' => 1, 'description' => 'd']],
        ]));

        $this->assertCount(0, $result['article']['added']);
        $this->assertCount(0, $result['article']['modified']);
        $this->assertGreaterThanOrEqual(5, count($result['article']['unchanged']));
    }

    public function test_apply_first_import_writes_and_creates_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);

        (new ArticleImportService())->apply($branch, $this->parsed([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B',
            'image_suggestions' => [['position' => 2, 'description' => 'x']],
        ]));

        $content = ArticleContent::where('content_branch_id', $branch->id)->firstOrFail();
        $this->assertSame('H', $content->headline);
        $this->assertSame('B', $content->body);
        $this->assertCount(1, $content->versions);
        $this->assertSame(1, $content->versions()->first()->version_no);
    }

    public function test_apply_unchanged_does_not_create_meaningless_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        ArticleContent::factory()->for($item)->for($branch)->create([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B', 'cover_suggestion' => null,
            'image_suggestions' => null,
        ]);

        (new ArticleImportService())->apply($branch, $this->parsed([
            'headline' => 'H', 'summary' => 'S', 'body' => 'B',
        ]));

        $this->assertCount(0, ArticleContentVersion::where('article_content_id',
            ArticleContent::where('content_branch_id', $branch->id)->firstOrFail()->id)->get());
    }

    public function test_invalid_structure_from_parser_is_rejected_by_apply(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);

        // 缺少 article 键属于结构非法，服务应抛异常（preview/apply 都做结构校验）。
        $this->expectException(\InvalidArgumentException::class);
        (new ArticleImportService())->apply($branch, ['item' => []]);
    }

    public function test_json_object_key_order_does_not_create_false_diff_or_version(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->branch($item);
        $content = ArticleContent::factory()->for($item)->for($branch)->create([
            'image_suggestions' => [['note' => 'N', 'position' => 1, 'description' => 'D']],
        ]);
        $parsed = $this->parsed(['image_suggestions' => [['position' => 1, 'description' => 'D', 'note' => 'N']]]);
        $service = new ArticleImportService();
        $this->assertSame([], $service->preview($branch, $parsed)['article']['modified']);
        $service->apply($branch, $parsed);
        $this->assertSame(0, $content->versions()->count());
        (new \App\Services\V2\ArticleManualService())->save($branch, $parsed['article']);
        $this->assertSame(0, $content->versions()->count());
    }
}
