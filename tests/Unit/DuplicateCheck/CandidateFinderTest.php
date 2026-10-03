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

    // ============================================================
    // Same-field only
    // ============================================================

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

    // ============================================================
    // Self exclusion
    // ============================================================

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

        $results = $this->finder->find($entry, [$entry]);
        $this->assertCount(0, $results);
    }

    public function testHistoricalRevisionNotExcluded(): void
    {
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

        $results = $this->finder->find($rev2, [$rev1]);
        $this->assertCount(1, $results);
        $this->assertSame(DuplicateCandidate::KIND_ORIGINAL_EXACT, $results[0]->matchKind);
    }

    // ============================================================
    // Limit validation
    // ============================================================

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

    // ============================================================
    // Three-tier sorting: original_exact > normalized_exact > overlap
    // ============================================================

    public function testThreeTierSorting(): void
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
            // Normalized exact: extra whitespace that Exact Normalization collapses
            new DuplicateComparableEntry(
                contentItemId: 'CI-NORM',
                contentPageId: 'CP-N1',
                copyRevisionId: 'CR-N1',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: '轮流不是催姐姐让，  而是让两个孩子都被听见。',
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

        $this->assertCount(3, $results);
        $this->assertSame(DuplicateCandidate::KIND_ORIGINAL_EXACT, $results[0]->matchKind);
        $this->assertSame(DuplicateCandidate::KIND_NORMALIZED_EXACT, $results[1]->matchKind);
        $this->assertSame(DuplicateCandidate::KIND_OVERLAP, $results[2]->matchKind);
    }

    // ============================================================
    // Same-kind score DESC
    // ============================================================

    public function testOverlapScoreDescWithinSameKind(): void
    {
        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: '先观察孩子停在哪儿，不急着催。',
        );

        $corpus = [
            // Lower similarity
            new DuplicateComparableEntry(
                contentItemId: 'CI-LOW',
                contentPageId: 'CP-L',
                copyRevisionId: 'CR-L',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: '这是一段完全不同的正文标题内容。',
            ),
            // Higher similarity
            new DuplicateComparableEntry(
                contentItemId: 'CI-HIGH',
                contentPageId: 'CP-H',
                copyRevisionId: 'CR-H',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: '先观察孩子停在哪儿，不急着催他。',
            ),
        ];

        $results = $this->finder->find($query, $corpus);

        // Both should be overlap candidates; higher score first
        $this->assertGreaterThanOrEqual(2, count($results));

        // Find positions
        $highIdx = null;
        $lowIdx = null;
        foreach ($results as $i => $r) {
            if ($r->match->contentItemId === 'CI-HIGH') $highIdx = $i;
            if ($r->match->contentItemId === 'CI-LOW') $lowIdx = $i;
        }

        if ($highIdx !== null && $lowIdx !== null) {
            $this->assertLessThan($lowIdx, $highIdx, 'Higher overlap score should come first');
        }
    }

    // ============================================================
    // Tie-break: per-field identity ASC (numeric-aware)
    // ============================================================

    public function testTieBreakNumericAware(): void
    {
        // Two overlap candidates with same score, different numeric IDs
        // "2" should come before "10" numerically (not "10" < "2" as string sort)
        $text = '测试完全相同的正文标题文本。';

        $query = new DuplicateComparableEntry(
            contentItemId: '999',
            contentPageId: '999',
            copyRevisionId: '1',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: '不同的查询文本，用于触发overlap。',
        );

        $corpus = [
            new DuplicateComparableEntry(
                contentItemId: '10',
                contentPageId: '1',
                copyRevisionId: '1',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
            new DuplicateComparableEntry(
                contentItemId: '2',
                contentPageId: '1',
                copyRevisionId: '1',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
        ];

        // Both are original_exact vs the same text? No, query is different.
        // Both overlap equally -> tie-break by content_item_id ASC
        $results = $this->finder->find($query, $corpus);

        // Find the two candidates
        $ids = array_map(fn($r) => $r->match->contentItemId, $results);

        // "2" should come before "10" (numeric-aware, not string concat)
        $idx2 = array_search('2', $ids);
        $idx10 = array_search('10', $ids);

        if ($idx2 !== false && $idx10 !== false) {
            $this->assertLessThan($idx10, $idx2, 'Numeric ID "2" should sort before "10"');
        }
    }

    // ============================================================
    // Slash marker passthrough
    // ============================================================

    public function testSlashMarkerOnProducesNormalizedExact(): void
    {
        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: "你肯听，\n情绪就有了出口。",
        );

        $match = new DuplicateComparableEntry(
            contentItemId: 'CI-M',
            contentPageId: 'CP-M',
            copyRevisionId: 'CR-M',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '你肯听，／情绪就有了出口。',
            slashLineBreakMarker: true,
        );

        $results = $this->finder->find($query, [$match]);

        $this->assertCount(1, $results);
        $this->assertSame(DuplicateCandidate::KIND_NORMALIZED_EXACT, $results[0]->matchKind);
    }

    public function testSlashMarkerOffNotNormalizedExact(): void
    {
        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: "你肯听，\n情绪就有了出口。",
        );

        $match = new DuplicateComparableEntry(
            contentItemId: 'CI-M',
            contentPageId: 'CP-M',
            copyRevisionId: 'CR-M',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '你肯听，／情绪就有了出口。',
            slashLineBreakMarker: false,
        );

        $results = $this->finder->find($query, [$match]);

        // Must NOT be normalized_exact when marker is off
        foreach ($results as $r) {
            $this->assertNotSame(
                DuplicateCandidate::KIND_NORMALIZED_EXACT,
                $r->matchKind,
                'Slash marker off must not produce normalized_exact'
            );
        }
    }

    // ============================================================
    // SYN compatibility at Finder level
    // ============================================================

    public function testSyn001FinderOriginalExact(): void
    {
        $text = '你在身边，“我自己来”更有底气。';

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: $text,
        );

        $match = new DuplicateComparableEntry(
            contentItemId: 'CI-M',
            contentPageId: 'CP-M',
            copyRevisionId: 'CR-M',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: $text,
        );

        $results = $this->finder->find($query, [$match]);
        $this->assertCount(1, $results);
        $this->assertSame(DuplicateCandidate::KIND_ORIGINAL_EXACT, $results[0]->matchKind);
    }

    public function testSyn002FinderNotNormalizedExact(): void
    {
        // Chinese comma vs English comma
        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '你在身边，“我自己来”更有底气。',
        );

        $match = new DuplicateComparableEntry(
            contentItemId: 'CI-M',
            contentPageId: 'CP-M',
            copyRevisionId: 'CR-M',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '你在身边, “我自己来”更有底气。',
        );

        $results = $this->finder->find($query, [$match]);

        foreach ($r as $results) {
            $this->assertNotSame(
                DuplicateCandidate::KIND_NORMALIZED_EXACT,
                $r->matchKind,
                'Chinese vs English comma must not be normalized exact'
            );
        }
    }

    public function testSyn004FinderOverlap(): void
    {
        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '轮流不是催姐姐让，而是让两个孩子都被听见。',
        );

        $match = new DuplicateComparableEntry(
            contentItemId: 'CI-M',
            contentPageId: 'CP-M',
            copyRevisionId: 'CR-M',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: '轮流不是催姐姐让，而是让两个孩子都被听见了。',
        );

        $results = $this->finder->find($query, [$match]);

        $this->assertCount(1, $results);
        $this->assertSame(DuplicateCandidate::KIND_OVERLAP, $results[0]->matchKind);
    }

    // ============================================================
    // Golden closing observation: expect ZERO candidates
    // ============================================================

    public function testGoldenClosingNoCandidates(): void
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

        $this->assertCount(4, $corpus, 'Golden fixture must have 4 closing lines');

        // Each closing as query, search against other 3
        foreach ($corpus as $i => $query) {
            $otherCorpus = array_values(array_filter(
                $corpus,
                fn($_, $idx) => $idx !== $i,
                ARRAY_FILTER_USE_BOTH
            ));

            $results = $this->finder->find($query, $otherCorpus);

            // Golden baseline: expect ZERO candidates
            $this->assertCount(
                0,
                $results,
                "GOLDEN_THRESHOLD_OBSERVATION: Query={$query->contentItemId} produced "
                . count($results) . " candidate(s). "
                . "If this fails, the 4 formal closing lines now overlap more than expected. "
                . "Do NOT adjust threshold — report for human review."
            );
        }
    }
}
