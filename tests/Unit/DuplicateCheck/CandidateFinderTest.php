<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\DuplicateCandidate;
use App\Services\DuplicateCheck\DuplicateCandidateFinder;
use App\Services\DuplicateCheck\DuplicateCheckService;
use App\Services\DuplicateCheck\DuplicateComparableEntry;
use App\Services\DuplicateCheck\DuplicateField;
use PHPUnit\Framework\TestCase;

class CandidateFinderTest extends TestCase
{
    private DuplicateCandidateFinder $finder;

    private DuplicateCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = new DuplicateCandidateFinder;
        $this->service = new DuplicateCheckService;
    }

    public function test_same_field_only(): void
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

    public function test_self_exclusion(): void
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

    public function test_historical_revision_not_excluded(): void
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

    public function test_limit_validation(): void
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

    public function test_three_tier_sorting(): void
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
            new DuplicateComparableEntry(
                contentItemId: 'CI-OVERLAP',
                contentPageId: 'CP-O1',
                copyRevisionId: 'CR-O1',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: '轮流不是催姐姐让，而是让两个孩子都被听见了。',
            ),
            new DuplicateComparableEntry(
                contentItemId: 'CI-NORM',
                contentPageId: 'CP-N1',
                copyRevisionId: 'CR-N1',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: $baseText.'  ',
            ),
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

    public function test_overlap_score_desc_within_same_kind(): void
    {
        $queryText = '轮流不是催姐姐让，而是让两个孩子都被听见。';
        $highText = '轮流不是催姐姐让，而是让两个孩子都被听见了。';
        $lowText = '轮流不是催姐姐让，而是让两个孩子也能被听见了。';

        $highResult = $this->service->compare($queryText, $highText, DuplicateField::ClosingLine);
        $lowResult = $this->service->compare($queryText, $lowText, DuplicateField::ClosingLine);

        $this->assertTrue($highResult->overlapCandidate, 'High text must be overlap candidate');
        $this->assertTrue($lowResult->overlapCandidate, 'Low text must be overlap candidate');
        $this->assertGreaterThan($lowResult->overlapScore, $highResult->overlapScore, 'High score must exceed low score');

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: $queryText,
        );

        $corpus = [
            new DuplicateComparableEntry(
                contentItemId: 'CI-LOW',
                contentPageId: 'CP-L',
                copyRevisionId: 'CR-L',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: $lowText,
            ),
            new DuplicateComparableEntry(
                contentItemId: 'CI-HIGH',
                contentPageId: 'CP-H',
                copyRevisionId: 'CR-H',
                pageNo: 9,
                pageType: 'column_closing',
                field: DuplicateField::ClosingLine,
                text: $highText,
            ),
        ];

        $results = $this->finder->find($query, $corpus);

        $this->assertCount(2, $results, 'Both high and low must produce candidates');
        $this->assertSame('CI-HIGH', $results[0]->match->contentItemId, 'Higher score must come first');
        $this->assertSame('CI-LOW', $results[1]->match->contentItemId, 'Lower score must come second');
    }

    public function test_tie_break_content_item_id_numeric(): void
    {
        $text = '完全相同的正文标题文本。';

        $query = new DuplicateComparableEntry(
            contentItemId: '999',
            contentPageId: '999',
            copyRevisionId: '1',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: $text,
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

        $results = $this->finder->find($query, $corpus);

        $this->assertCount(2, $results);
        $this->assertSame('2', $results[0]->match->contentItemId, 'Numeric ID "2" must sort before "10"');
        $this->assertSame('10', $results[1]->match->contentItemId);
    }

    public function test_tie_break_copy_revision_id_numeric(): void
    {
        $text = '完全相同的正文标题文本。';

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-TEST',
            contentPageId: 'CP-TEST',
            copyRevisionId: '99',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: $text,
        );

        $corpus = [
            new DuplicateComparableEntry(
                contentItemId: 'CI-TEST',
                contentPageId: 'CP-TEST',
                copyRevisionId: '10',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
            new DuplicateComparableEntry(
                contentItemId: 'CI-TEST',
                contentPageId: 'CP-TEST',
                copyRevisionId: '2',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
        ];

        $results = $this->finder->find($query, $corpus);

        $this->assertCount(2, $results);
        $this->assertSame('2', $results[0]->match->copyRevisionId, 'Numeric revision "2" must sort before "10"');
        $this->assertSame('10', $results[1]->match->copyRevisionId);
    }

    public function test_tie_break_content_page_id_numeric(): void
    {
        $text = '完全相同的正文标题文本。';

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-TEST',
            contentPageId: '999',
            copyRevisionId: '1',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: $text,
        );

        $corpus = [
            new DuplicateComparableEntry(
                contentItemId: 'CI-TEST',
                contentPageId: '10',
                copyRevisionId: '1',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
            new DuplicateComparableEntry(
                contentItemId: 'CI-TEST',
                contentPageId: '2',
                copyRevisionId: '1',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
        ];

        $results = $this->finder->find($query, $corpus);

        $this->assertCount(2, $results);
        $this->assertSame('2', $results[0]->match->contentPageId, 'Numeric page ID "2" must sort before "10"');
        $this->assertSame('10', $results[1]->match->contentPageId);
    }

    public function test_tie_break_non_numeric_string(): void
    {
        $text = '完全相同的正文标题文本。';

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-ZZZ',
            contentPageId: 'CP-ZZZ',
            copyRevisionId: 'CR-ZZZ',
            pageNo: 2,
            pageType: 'content',
            field: DuplicateField::PageTitle,
            text: $text,
        );

        $corpus = [
            new DuplicateComparableEntry(
                contentItemId: 'CI-B',
                contentPageId: 'CP-B',
                copyRevisionId: 'CR-B',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
            new DuplicateComparableEntry(
                contentItemId: 'CI-A',
                contentPageId: 'CP-A',
                copyRevisionId: 'CR-A',
                pageNo: 2,
                pageType: 'content',
                field: DuplicateField::PageTitle,
                text: $text,
            ),
        ];

        $results = $this->finder->find($query, $corpus);

        $this->assertCount(2, $results);
        $this->assertSame('CI-A', $results[0]->match->contentItemId, 'String ID CI-A must sort before CI-B');
        $this->assertSame('CI-B', $results[1]->match->contentItemId);
    }

    public function test_slash_marker_on_produces_normalized_exact(): void
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

    public function test_slash_marker_off_not_normalized_exact(): void
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

        $this->assertCount(1, $results);
        foreach ($results as $r) {
            $this->assertNotSame(
                DuplicateCandidate::KIND_NORMALIZED_EXACT,
                $r->matchKind,
                'Slash marker off must not produce normalized_exact'
            );
        }
    }

    public function test_syn001_finder_original_exact(): void
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

    public function test_syn002_finder_not_normalized_exact(): void
    {
        $textA = '你在身边，“我自己来”更有底气。';
        $textB = '你在身边, “我自己来”更有底气。';

        $svcResult = $this->service->compare($textA, $textB, DuplicateField::ClosingLine);
        $this->assertFalse($svcResult->originalExact);
        $this->assertFalse($svcResult->normalizedExact, 'Chinese vs English comma must not be normalized exact');
        $this->assertGreaterThan(0, $svcResult->overlapScore);
        $this->assertTrue($svcResult->overlapCandidate);
        $this->assertSame(0.4, $svcResult->threshold);

        $query = new DuplicateComparableEntry(
            contentItemId: 'CI-Q',
            contentPageId: 'CP-Q',
            copyRevisionId: 'CR-Q',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: $textA,
        );

        $match = new DuplicateComparableEntry(
            contentItemId: 'CI-M',
            contentPageId: 'CP-M',
            copyRevisionId: 'CR-M',
            pageNo: 9,
            pageType: 'column_closing',
            field: DuplicateField::ClosingLine,
            text: $textB,
        );

        $results = $this->finder->find($query, [$match]);

        $this->assertCount(1, $results);
        $this->assertSame(DuplicateCandidate::KIND_OVERLAP, $results[0]->matchKind);
        $this->assertFalse($results[0]->normalizedExact);
        $this->assertTrue($results[0]->overlapCandidate);
        $this->assertEqualsWithDelta($svcResult->overlapScore, $results[0]->overlapScore, 0.000001);
    }

    public function test_syn004_finder_overlap(): void
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

    public function test_golden_closing_no_candidates(): void
    {
        $fixturePath = dirname(__DIR__, 3).'/tests/Fixtures/duplicate_check/yujian_history_baseline.json';
        $json = json_decode(file_get_contents($fixturePath), true);

        $corpus = [];
        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                if ($page['page_type'] === 'column_closing' && isset($page['closing_line'])) {
                    $corpus[] = new DuplicateComparableEntry(
                        contentItemId: $item['legacy_id'],
                        contentPageId: $item['legacy_id'].'-P'.$page['page_no'],
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

        foreach ($corpus as $i => $query) {
            $otherCorpus = array_values(array_filter(
                $corpus,
                fn ($_, $idx) => $idx !== $i,
                ARRAY_FILTER_USE_BOTH
            ));

            $results = $this->finder->find($query, $otherCorpus);

            $message = "GOLDEN_THRESHOLD_OBSERVATION: Query={$query->contentItemId} produced "
                .count($results).' candidate(s). ';

            if (count($results) > 0) {
                $first = $results[0];
                $message .= "First candidate: match_item={$first->match->contentItemId} "
                    ."kind={$first->matchKind} "
                    ."score={$first->overlapScore} "
                    ."threshold={$first->threshold}. ";
            }

            $message .= 'Do NOT adjust threshold — report for human review.';

            $this->assertCount(0, $results, $message);
        }
    }
}
