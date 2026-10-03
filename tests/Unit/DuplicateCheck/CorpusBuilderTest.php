<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\DuplicateCorpusBuilder;
use App\Services\DuplicateCheck\DuplicateField;
use PHPUnit\Framework\TestCase;

class CorpusBuilderTest extends TestCase
{
    private DuplicateCorpusBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DuplicateCorpusBuilder();
    }

    public function testCoverPageProducesCoverTitleAndSubtitle(): void
    {
        $pages = [[
            'content_item_id' => 'CI-001',
            'content_page_id' => 'CP-001',
            'copy_revision_id' => 'CR-001',
            'page_no_snapshot' => 1,
            'page_type_snapshot' => 'cover',
            'cover_title' => '测试标题',
            'cover_subtitle' => '测试副标题',
            'page_title' => null,
            'page_small_text' => null,
            'closing_line' => null,
        ]];

        $entries = $this->builder->build($pages);

        $this->assertCount(2, $entries);
        $this->assertSame(DuplicateField::CoverTitle, $entries[0]->field);
        $this->assertSame(DuplicateField::CoverSubtitle, $entries[1]->field);
    }

    public function testContentPageProducesPageTitleAndSmallText(): void
    {
        $pages = [[
            'content_item_id' => 'CI-001',
            'content_page_id' => 'CP-002',
            'copy_revision_id' => 'CR-001',
            'page_no_snapshot' => 2,
            'page_type_snapshot' => 'content',
            'cover_title' => null,
            'cover_subtitle' => null,
            'page_title' => '正文标题',
            'page_small_text' => '正文小字',
            'closing_line' => null,
        ]];

        $entries = $this->builder->build($pages);

        $this->assertCount(2, $entries);
        $this->assertSame(DuplicateField::PageTitle, $entries[0]->field);
        $this->assertSame(DuplicateField::PageSmallText, $entries[1]->field);
    }

    public function testColumnClosingProducesClosingLine(): void
    {
        $pages = [[
            'content_item_id' => 'CI-001',
            'content_page_id' => 'CP-009',
            'copy_revision_id' => 'CR-001',
            'page_no_snapshot' => 9,
            'page_type_snapshot' => 'column_closing',
            'cover_title' => null,
            'cover_subtitle' => null,
            'page_title' => null,
            'page_small_text' => null,
            'closing_line' => '你在身边，“我自己来”更有底气。',
        ]];

        $entries = $this->builder->build($pages);

        $this->assertCount(1, $entries);
        $this->assertSame(DuplicateField::ClosingLine, $entries[0]->field);
    }

    public function testFixedBackCoverProducesNoEntries(): void
    {
        $pages = [[
            'content_item_id' => 'CI-001',
            'content_page_id' => 'CP-010',
            'copy_revision_id' => 'CR-001',
            'page_no_snapshot' => 10,
            'page_type_snapshot' => 'fixed_back_cover',
            'cover_title' => null,
            'cover_subtitle' => null,
            'page_title' => null,
            'page_small_text' => null,
            'closing_line' => null,
        ]];

        $entries = $this->builder->build($pages);

        $this->assertCount(0, $entries);
    }

    public function testNullAndEmptyTextSkipped(): void
    {
        $pages = [[
            'content_item_id' => 'CI-001',
            'content_page_id' => 'CP-002',
            'copy_revision_id' => 'CR-001',
            'page_no_snapshot' => 2,
            'page_type_snapshot' => 'content',
            'cover_title' => null,
            'cover_subtitle' => null,
            'page_title' => null,
            'page_small_text' => '   ',
            'closing_line' => null,
        ]];

        $entries = $this->builder->build($pages);

        $this->assertCount(0, $entries);
    }

    /**
     * Golden regression: read the actual fixture and verify the builder
     * produces the expected number of comparable entries from 4 items / 37 pages.
     */
    public function testGoldenDatasetBuild(): void
    {
        $fixturePath = base_path('tests/Fixtures/duplicate_check/yujian_history_baseline.json');
        $json = json_decode(file_get_contents($fixturePath), true);

        // 4 items / 37 pages
        $this->assertCount(4, $json['items']);
        $totalPages = 0;
        foreach ($json['items'] as $item) {
            $totalPages += count($item['pages']);
        }
        $this->assertSame(37, $totalPages);

        $pages = [];
        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                $pages[] = [
                    'content_item_id' => $item['legacy_id'],
                    'content_page_id' => $item['legacy_id'] . '-P' . $page['page_no'],
                    'copy_revision_id' => 'CR-FORMAL-001',
                    'page_no_snapshot' => $page['page_no'],
                    'page_type_snapshot' => $page['page_type'],
                    'cover_title' => $page['cover_title'] ?? null,
                    'cover_subtitle' => $page['cover_subtitle'] ?? null,
                    'page_title' => $page['page_title'] ?? null,
                    'page_small_text' => $page['page_small_text'] ?? null,
                    'closing_line' => $page['closing_line'] ?? null,
                ];
            }
        }

        $entries = $this->builder->build($pages);

        // Expected: 4 cover * 2 fields = 8, 25 content * 2 fields = 50,
        //           4 column_closing * 1 field = 4, 4 fixed_back_cover * 0 = 0
        //           Total = 62
        $this->assertCount(62, $entries, 'GOLDEN_CORPUS_COUNT mismatch');

        // Allowed fields only
        $allowedFields = [
            DuplicateField::CoverTitle,
            DuplicateField::CoverSubtitle,
            DuplicateField::PageTitle,
            DuplicateField::PageSmallText,
            DuplicateField::ClosingLine,
        ];

        foreach ($entries as $entry) {
            $this->assertContains(
                $entry->field,
                $allowedFields,
                'Unexpected field type in golden corpus: ' . $entry->field->name
            );
        }

        // Explicitly verify closing_line count = 4 (one per item)
        $closingEntries = array_filter(
            $entries,
            fn($e) => $e->field === DuplicateField::ClosingLine
        );
        $this->assertCount(4, $closingEntries, 'Expected exactly 4 closing_line entries from 4 column_closing pages');

        // Channel-specific closing must NOT appear in formal corpus
        $hasChannelClosing = false;
        foreach ($entries as $entry) {
            if ($entry->field === DuplicateField::ClosingLine && str_contains($entry->text, '是她自己来的底气')) {
                $hasChannelClosing = true;
            }
        }
        $this->assertFalse($hasChannelClosing, 'Channel-specific closing must not be in formal corpus');
    }
}
