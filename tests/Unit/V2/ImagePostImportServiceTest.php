<?php

namespace Tests\Unit\V2;

use App\Enums\ContentFormat;
use App\Enums\PageType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Services\V2\ImagePostImportParser;
use App\Services\V2\ImagePostImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * RED: service + parser do not exist yet — these must fail until GREEN.
 *
 * 固定流程：raw → parse → normalize → validate → preview diff → confirm apply。
 * preview 只读，绝不写入正式内容；只有 apply 才落库并保留版本。
 */
class ImagePostImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ImagePostImportService
    {
        return new ImagePostImportService();
    }

    private function parser(): ImagePostImportParser
    {
        return new ImagePostImportParser();
    }

    private function imagePostBranch(ContentItem $item, ContentFormat $format = ContentFormat::ImagePost): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create(['format' => $format]);
    }

    private function page(ContentItem $item, int $pageNo, PageType $type, array $fields = []): ContentPage
    {
        return ContentPage::factory()->for($item)->create(array_merge([
            'page_no' => $pageNo,
            'page_type' => $type,
            'narrative_role' => null,
            'new_fact' => null,
            'final_copy' => null,
            'visual_evidence' => null,
            'continuity' => null,
            'production_note' => null,
        ], $fields));
    }

    private function itemRaw(array $pages, array $item = []): string
    {
        $head = '';
        if (isset($item['core_message'])) {
            $head .= "核心表达: {$item['core_message']}\n";
        }
        if (isset($item['story_line'])) {
            $head .= "故事线: {$item['story_line']}\n";
        }
        $blocks = '';
        foreach ($pages as $p) {
            $blocks .= "\n--- 第{$p['page_no']}页 ---\n";
            $blocks .= "页码: {$p['page_no']}\n";
            $blocks .= "页面类型: {$p['page_type']}\n";
            $blocks .= "叙事职责: ".($p['narrative_role'] ?? '')."\n";
            $blocks .= "本页新事实: ".($p['new_fact'] ?? '')."\n";
            $blocks .= "最终文字: ".($p['final_copy'] ?? '')."\n";
            $blocks .= "画面证据: ".($p['visual_evidence'] ?? '')."\n";
            $blocks .= "前后页承接: ".($p['continuity'] ?? '')."\n";
            $blocks .= "制作备注: ".($p['production_note'] ?? '')."\n";
        }

        return $head.$blocks;
    }

    public function test_preview_does_not_write_to_database(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $existing = $this->page($item, 1, PageType::Content, [
            'narrative_role' => 'old role', 'final_copy' => 'old copy',
        ]);

        $raw = $this->itemRaw([[
            'page_no' => 1, 'page_type' => 'content',
            'narrative_role' => 'new role', 'final_copy' => 'new copy',
        ]]);
        $parsed = $this->parser()->parse($raw);

        $this->service()->preview($branch, $parsed);

        // 未 confirm：正式内容不得被改写。
        $this->assertSame('old role', $existing->refresh()->narrative_role);
        $this->assertSame('old copy', $existing->refresh()->final_copy);
        $this->assertDatabaseCount('content_pages', 1);
        $this->assertDatabaseCount('content_page_versions', 0);
    }

    public function test_full_import_preview_classifies_added_modified_unchanged(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->page($item, 1, PageType::Content, ['final_copy' => 'keep']); // unchanged
        $this->page($item, 3, PageType::Content, ['final_copy' => 'before']); // modified

        $raw = $this->itemRaw([
            ['page_no' => 1, 'page_type' => 'content', 'final_copy' => 'keep'],
            ['page_no' => 2, 'page_type' => 'content', 'final_copy' => 'brand new'],
            ['page_no' => 3, 'page_type' => 'content', 'final_copy' => 'after'],
        ]);
        $parsed = $this->parser()->parse($raw);

        $preview = $this->service()->preview($branch, $parsed);

        $addedNos = array_column($preview['pages']['added'], 'page_no');
        $modifiedNos = array_column($preview['pages']['modified'], 'page_no');
        $unchangedNos = array_column($preview['pages']['unchanged'], 'page_no');
        $this->assertSame([2], $addedNos);
        $this->assertSame([3], $modifiedNos);
        $this->assertSame([1], $unchangedNos);
    }

    public function test_partial_import_preview_only_covers_input_pages(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->page($item, 3, PageType::Content, ['final_copy' => 'keep']);

        // 局部导入：只粘贴了第 1、2 页，没有第 3 页。
        $raw = $this->itemRaw([
            ['page_no' => 1, 'page_type' => 'content', 'final_copy' => 'new'],
            ['page_no' => 2, 'page_type' => 'content', 'final_copy' => 'new2'],
        ]);
        $parsed = $this->parser()->parse($raw);

        $preview = $this->service()->preview($branch, $parsed);

        $this->assertSame([1, 2], array_column($preview['pages']['added'], 'page_no'));
        $this->assertSame([], $preview['pages']['modified']);
        $this->assertSame([], $preview['pages']['unchanged']);
    }

    public function test_modified_entry_expresses_page_no_field_before_after(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->page($item, 1, PageType::Content, ['final_copy' => 'before', 'narrative_role' => 'r']);

        $raw = $this->itemRaw([['page_no' => 1, 'page_type' => 'content', 'final_copy' => 'after', 'narrative_role' => 'r']]);
        $parsed = $this->parser()->parse($raw);

        $preview = $this->service()->preview($branch, $parsed);

        $this->assertCount(1, $preview['pages']['modified']);
        $change = $preview['pages']['modified'][0];
        $this->assertSame(1, $change['page_no']);
        $fieldChange = collect($change['changes'])->firstWhere('field', 'final_copy');
        $this->assertNotNull($fieldChange);
        $this->assertSame('before', $fieldChange['before']);
        $this->assertSame('after', $fieldChange['after']);
    }

    public function test_existing_fixed_back_cover_is_protected(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $protected = $this->page($item, 5, PageType::FixedBackCover, ['final_copy' => 'DO NOT TOUCH']);

        $raw = $this->itemRaw([['page_no' => 5, 'page_type' => 'fixed_back_cover', 'final_copy' => 'hacked']]);
        $parsed = $this->parser()->parse($raw);

        $preview = $this->service()->preview($branch, $parsed);
        $this->assertNotEmpty($preview['warnings']);
        $this->assertSame([], $preview['pages']['modified']);

        $this->service()->apply($branch, $parsed);
        $this->assertSame('DO NOT TOUCH', $protected->refresh()->final_copy);
    }

    public function test_new_fixed_back_cover_is_rejected(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);

        $raw = $this->itemRaw([['page_no' => 9, 'page_type' => 'fixed_back_cover', 'final_copy' => 'x']]);
        $parsed = $this->parser()->parse($raw);

        $preview = $this->service()->preview($branch, $parsed);
        $this->assertNotEmpty($preview['warnings']);

        $this->service()->apply($branch, $parsed);
        $this->assertNull(ContentPage::where('page_no', 9)->first());
    }

    public function test_rejects_non_image_post_branch(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item, ContentFormat::Article);
        $raw = $this->itemRaw([['page_no' => 1, 'page_type' => 'content', 'final_copy' => 'x']]);
        $parsed = $this->parser()->parse($raw);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->preview($branch, $parsed);
    }

    public function test_apply_creates_pages_versions_and_updates_item(): void
    {
        $item = ContentItem::factory()->create(['core_message' => null, 'story_line' => null]);
        $branch = $this->imagePostBranch($item);

        $raw = $this->itemRaw(
            [
                ['page_no' => 1, 'page_type' => 'cover', 'final_copy' => 'c1', 'narrative_role' => 'r1'],
                ['page_no' => 2, 'page_type' => 'content', 'final_copy' => 'c2', 'narrative_role' => 'r2'],
            ],
            ['core_message' => 'CM', 'story_line' => 'SL']
        );
        $parsed = $this->parser()->parse($raw);

        $this->service()->apply($branch, $parsed);

        $this->assertDatabaseCount('content_pages', 2);
        $this->assertSame('CM', $item->refresh()->core_message);
        $this->assertSame('SL', $item->refresh()->story_line);
        // 每个被应用的页面都应保留一个版本快照。
        $this->assertSame(2, ContentPageVersion::count());
        foreach (ContentPage::orderBy('page_no')->get() as $page) {
            $this->assertSame(1, $page->versions()->count());
        }
    }

    public function test_page_no_is_stable_identity(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $existing = $this->page($item, 1, PageType::Content, ['final_copy' => 'old']);

        $raw = $this->itemRaw([['page_no' => 1, 'page_type' => 'content', 'final_copy' => 'new']]);
        $parsed = $this->parser()->parse($raw);

        $this->service()->apply($branch, $parsed);

        $this->assertDatabaseCount('content_pages', 1);
        $this->assertSame($existing->id, ContentPage::first()->id);
        $this->assertSame('new', ContentPage::first()->final_copy);
    }

    public function test_four_page_types_are_accepted_in_flow(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        $this->page($item, 1, PageType::Cover, ['final_copy' => 'k']);
        $this->page($item, 2, PageType::Content, ['final_copy' => 'k']);
        $this->page($item, 3, PageType::ColumnClosing, ['final_copy' => 'k']);

        $raw = $this->itemRaw([
            ['page_no' => 1, 'page_type' => 'cover', 'final_copy' => 'k'],
            ['page_no' => 2, 'page_type' => 'content', 'final_copy' => 'changed'],
            ['page_no' => 3, 'page_type' => 'column_closing', 'final_copy' => 'k'],
        ]);
        $parsed = $this->parser()->parse($raw);

        $preview = $this->service()->preview($branch, $parsed);
        $this->assertSame([2], array_column($preview['pages']['modified'], 'page_no'));
        $this->assertSame([1, 3], array_column($preview['pages']['unchanged'], 'page_no'));
    }

    public function test_eight_fields_per_page_are_stored(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);

        $raw = $this->itemRaw([[
            'page_no' => 1, 'page_type' => 'content',
            'narrative_role' => 'NR', 'new_fact' => 'NF', 'final_copy' => 'FC',
            'visual_evidence' => 'VE', 'continuity' => 'CO', 'production_note' => 'PN',
        ]]);
        $parsed = $this->parser()->parse($raw);
        $this->service()->apply($branch, $parsed);

        $page = ContentPage::first();
        $this->assertSame(1, $page->page_no);
        $this->assertSame('content', $page->page_type->value);
        $this->assertSame('NR', $page->narrative_role);
        $this->assertSame('NF', $page->new_fact);
        $this->assertSame('FC', $page->final_copy);
        $this->assertSame('VE', $page->visual_evidence);
        $this->assertSame('CO', $page->continuity);
        $this->assertSame('PN', $page->production_note);
    }

    public function test_service_throws_on_structurally_invalid_page(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->imagePostBranch($item);
        // page_type 缺失 → 结构性错误。
        $parsed = ['item' => [], 'pages' => [['page_no' => 1, 'narrative_role' => 'x']]];

        $this->expectException(InvalidArgumentException::class);
        $this->service()->apply($branch, $parsed);
    }
}
