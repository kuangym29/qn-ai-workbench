<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\DuplicateCheckService;
use App\Services\DuplicateCheck\DuplicateField;
use PHPUnit\Framework\TestCase;

class GoldenDatasetRegressionTest extends TestCase
{
    private string $fixturePath;

    private DuplicateCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Pure PHPUnit: resolve the fixture from the repository root without the Laravel
        // container. __DIR__ is <root>/tests/Unit/DuplicateCheck, so three levels up is
        // the repository root.
        $this->fixturePath = dirname(__DIR__, 3)
            .'/tests/Fixtures/duplicate_check/yujian_history_baseline.json';
        $this->service = new DuplicateCheckService;
    }

    public function test_golden_json_parsable(): void
    {
        $this->assertFileExists($this->fixturePath);
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $this->assertIsArray($json, 'Golden dataset JSON must be valid');
    }

    public function test_golden_item_count(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $this->assertCount(4, $json['items'], 'Must have exactly 4 items');
    }

    public function test_golden_page_count(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $totalPages = 0;
        foreach ($json['items'] as $item) {
            $totalPages += count($item['pages']);
        }
        $this->assertSame(37, $totalPages, 'Must have exactly 37 pages');
    }

    public function test_golden_page_type_distribution(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $counts = ['cover' => 0, 'content' => 0, 'column_closing' => 0, 'fixed_back_cover' => 0];

        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                $counts[$page['page_type']]++;
            }
        }

        $this->assertSame(4, $counts['cover']);
        $this->assertSame(25, $counts['content']);
        $this->assertSame(4, $counts['column_closing']);
        $this->assertSame(4, $counts['fixed_back_cover']);
    }

    public function test_golden_formal_closing_lines(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $closingLines = [];

        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                if ($page['page_type'] === 'column_closing' && isset($page['closing_line'])) {
                    $closingLines[] = [
                        'item' => $item['title'],
                        'closing' => $page['closing_line'],
                    ];
                }
            }
        }

        $this->assertCount(4, $closingLines, 'Must have exactly 4 formal closing lines');
    }

    public function test_golden_formal_closing_pairwise_not_exact(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $closingLines = [];

        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                if ($page['page_type'] === 'column_closing' && isset($page['closing_line'])) {
                    $closingLines[] = $page['closing_line'];
                }
            }
        }

        // All 6 pairs must NOT be exact (original or normalized)
        for ($i = 0; $i < count($closingLines); $i++) {
            for ($j = $i + 1; $j < count($closingLines); $j++) {
                $result = $this->service->compare($closingLines[$i], $closingLines[$j], DuplicateField::ClosingLine);
                $this->assertFalse($result->originalExact, "Pair ($i, $j) should not be original exact");
                $this->assertFalse($result->normalizedExact, "Pair ($i, $j) should not be normalized exact");
            }
        }
    }

    /**
     * Golden pairwise overlap observation.
     *
     * DEV-D08 baseline: no natural cross-item overlap candidates among the 4
     * formal closing lines. If any pair crosses the closing_line threshold
     * (0.4), this test fails and reports GOLDEN_THRESHOLD_OBSERVATION.
     *
     * We do NOT silently raise the threshold to make tests pass.
     */
    public function test_golden_formal_closing_pairwise_overlap_observation(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $closingLines = [];
        $itemTitles = [];

        foreach ($json['items'] as $item) {
            foreach ($item['pages'] as $page) {
                if ($page['page_type'] === 'column_closing' && isset($page['closing_line'])) {
                    $closingLines[] = $page['closing_line'];
                    $itemTitles[] = $item['title'];
                }
            }
        }

        $observations = [];
        for ($i = 0; $i < count($closingLines); $i++) {
            for ($j = $i + 1; $j < count($closingLines); $j++) {
                $result = $this->service->compare($closingLines[$i], $closingLines[$j], DuplicateField::ClosingLine);
                $observations[] = [
                    'pair' => $itemTitles[$i].' vs '.$itemTitles[$j],
                    'score' => $result->overlapScore,
                    'threshold' => $result->threshold,
                    'candidate' => $result->overlapCandidate,
                ];

                // Per DEV-D08 baseline: no golden overlap candidates expected.
                // If any is true, this test fails loudly.
                $this->assertFalse(
                    $result->overlapCandidate,
                    "Unexpected golden overlap candidate: {$itemTitles[$i]} vs {$itemTitles[$j]} "
                    ."score={$result->overlapScore} threshold={$result->threshold}. "
                    .'This is a GOLDEN_THRESHOLD_OBSERVATION — do not silently adjust threshold.'
                );
            }
        }

        // Output observations for visibility (via test output)
        fwrite(STDERR, "\nGolden pairwise overlap observations:\n");
        foreach ($observations as $obs) {
            fwrite(STDERR, sprintf(
                "  %s: score=%.4f threshold=%.1f candidate=%s\n",
                $obs['pair'],
                $obs['score'],
                $obs['threshold'],
                $obs['candidate'] ? 'YES' : 'no'
            ));
        }
    }

    public function test_golden_channel_specific_closing_not_in_formal(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);

        // Get channel-specific closing
        $channelClosing = $json['channel_specific_examples']['wechat_channels']['CI-LIFE-001']['closing_line_channel_specific'] ?? null;
        $this->assertNotNull($channelClosing, 'Channel-specific closing must exist');

        // Get formal closing of CI-LIFE-001
        $formalClosing = null;
        foreach ($json['items'] as $item) {
            if ($item['legacy_id'] === 'CI-LIFE-001') {
                foreach ($item['pages'] as $page) {
                    if ($page['page_type'] === 'column_closing') {
                        $formalClosing = $page['closing_line'];
                    }
                }
            }
        }

        $this->assertNotNull($formalClosing);
        $this->assertNotSame($channelClosing, $formalClosing, 'Channel-specific closing must differ from formal closing');
    }

    public function test_golden_column_slugs(): void
    {
        $json = json_decode(file_get_contents($this->fixturePath), true);
        $slugs = array_column($json['items'], 'column_slug');

        $this->assertContains('life-skills', $slugs);
        $this->assertContains('emotions', $slugs);
        $this->assertContains('siblings-social', $slugs);
        $this->assertContains('growing-up', $slugs);
    }
}
