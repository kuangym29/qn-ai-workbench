<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\DuplicateCandidateFinder;
use App\Services\DuplicateCheck\DuplicateComparableEntry;
use App\Services\DuplicateCheck\DuplicateCandidate;
use App\Services\DuplicateCheck\DuplicateField;
use PHPUnit\Framework\TestCase;

class CandidateFinderTest extends TestCase
{
    private DuplicateCandidateFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = new DuplicateCandidateFinder();
    }

    public function testSameFieldOnly(): void
    {
        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-001',
            contentPageId: 'CP-001',
            copyRevisionId: 'CR-001',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '你在身边，“我自己来”更有底气。',
        );

        // Same text but different field -> should NOT match
        $corpus = [
            new DuplicateComparableEntry(
                contentItemId: 'CI-002',
                contentPageId: 'CP-002',
                copyRevisionId: 'CR-001',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: '你在身边，“我自己来”更有底气。',
            ),
        ];

        $results = $this->finder->find($query, $corpus);
        $this->assertCount(0, $results);
    }

    public function testSelfExclusion(): void
    {
        $entry = new DuplicateComparableEntry(
            contentItemId: 'CI-001',
            contentPageId: 'CP-001',
            copyRevisionId: 'CR-001',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: '测试标题',
        );

        // Same identity -> excluded
        $results = $this->finder->find($entry, [$entry]);
        $this->assertCount(0, $results);
    }

    public function testHistoricalRevisionNotExcluded(): void
    {
        // Same item + same page + same field, but different revision
        $rev1 = new DuplicateComparableEntry(
            contentItemId: 'CI-001',
            contentPageId: 'CP-002',
            copyRevisionId: 'CR-001',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: '测试标题',
        );

        $rev2 = new DuplicateComparableEntry(
            contentItemId: 'CI-001',
            contentPageId: 'CP-002',
            copyRevisionId: 'CR-002',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: '测试标题',
        );

        // Query = rev2, corpus contains rev1 -> should find exact match
        $results = $this->finder->find($rev2, [$rev1]);
        $this->assertCount(1, $results);
        $this->assertSame(DuplicateCandidate::KIND_ORIGINAL_EXACT, $results[0]->matchKind);
    }

    public function testLimitValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-001',
            contentPageId: 'CP-001',
            copyRevisionId: 'CR-001',
            pageNo: 1,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: '测试',
        );

        $this->finder->find($query, [], limit: 0);
    }

    public function testSortingOrder(): void
    {
        $baseText = '轮流不是催姐姐让，而是让两个孩子都被听见。';

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: $baseText,
        );

        $corpus = [
            // Overlap candidate (similar but not exact)
            new DuplicateComparableEntry(
                contentItemId: 'CI-OVERLAP',
                contentPageId: 'CP-O1',
                copyRevisionId: 'CR-O1',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: '轮流不是催姐姐让，而是让两个孩子都被听见了。',
            ),
            // Original exact
            new DuplicateComparableEntry(
                contentItemId: 'CI-EXACT',
                contentPageId: 'CP-E1',
                copyRevisionId: 'CR-E1',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: $baseText,
            ),
        ];

        $results = $this->finder->find($query, $corpus);

        // Original exact should come first
        $this->assertCount(2, $results);
        $this->assertSame(DuplicateCandidate::KIND_ORIGINAL_EXACT, $results[0]->matchKind);
        $this->assertSame(DuplicateCandidate::KIND_OVERLAP, $results[1]->matchKind);
    }

    public function testGoldenClosingNoExactCandidates(): void
    {
        $fixturePath = base_path('tests/Fixtures/duplicate_check/yujian_history_baseline.json');
        $json = json_decode(file_get_contents($fixturePath), true);

        // Build closing-line corpus from 4 items
        $corpus = [];
        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                if ($page['page_type'] === 'column_closing' && isset($page['closing_line'])) {
                    $corpus[] = new DuplicateComparableEntry(
                        contentItemId: $item['legacy_id'],
                        contentPageId: $item['legacy_id'] . '-P' . $page['page_no'],
                        copyRevisionId: 'CR-FORMAL-001',
                        pageNo: $page['page_no'],
                        pageType: 'column_closing',
                        field: DuplicateField::ClosingLine,
                        text: $page['closing_line'],
                    );
                }
            }
        }

        // Each closing as query, search against other 3
        foreach ($corpus as $i => $query) {
            $otherCorpus = array_filter($corpus, fn($_, $idx) => $idx !== $i, ARRAY_FILTER_USE_BOTH);
            $results = $this->finder->find($query, $otherCorpus);

            foreach ($results as $result) {
                $this->assertNotSame(
                    DuplicateCandidate::KIND_ORIGINAL_EXACT,
                    $result->matchKind,
                    "Golden closing should not produce original exact candidate"
                );
                $this->assertNotSame(
                    DuplicateCandidate::KIND_NORMALIZED_EXACT,
                    $result->matchKind,
                    "Golden closing should not produce normalized exact candidate"
                );
            }
        }
    }
}
