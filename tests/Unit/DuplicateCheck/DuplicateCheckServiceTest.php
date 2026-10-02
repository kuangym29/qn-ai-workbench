<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\DuplicateCheckService;
use App\Services\DuplicateCheck\DuplicateField;
use PHPUnit\Framework\TestCase;

class DuplicateCheckServiceTest extends TestCase
{
    private DuplicateCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DuplicateCheckService();
    }

    public function testSyn001IdenticalText(): void
    {
        $a = "你在身边，“我自己来”更有底气。";
        $b = "你在身边，“我自己来”更有底气。";

        $result = $this->service->compare($a, $b, DuplicateField::ClosingLine);

        $this->assertTrue($result->originalExact);
        $this->assertTrue($result->normalizedExact);
        $this->assertFalse($result->overlapCandidate);
    }

    public function testSyn002CommaDifferenceNotExact(): void
    {
        // Chinese comma vs English comma -> not normalized exact
        $a = "你在身边，“我自己来”更有底气。";
        $b = "你在身边, “我自己来”更有底气。";

        $result = $this->service->compare($a, $b, DuplicateField::ClosingLine);

        $this->assertFalse($result->originalExact);
        $this->assertFalse($result->normalizedExact);
        // Should enter overlap calculation
        $this->assertGreaterThan(0, $result->overlapScore);
    }

    public function testSyn003SlashMarkerOn(): void
    {
        $a = "你肯听，\n情绪就有了出口。";
        $b = "你肯听，／情绪就有了出口。";

        // B has slash marker -> should become normalized exact
        $result = $this->service->compare($a, $b, DuplicateField::ClosingLine, rightSlashLineBreakMarker: true);

        $this->assertFalse($result->originalExact);
        $this->assertTrue($result->normalizedExact);
        $this->assertFalse($result->overlapCandidate);
    }

    public function testSyn003SlashMarkerOff(): void
    {
        $a = "你肯听，\n情绪就有了出口。";
        $b = "你肯听，／情绪就有了出口。";

        // B does NOT have slash marker -> NOT normalized exact
        $result = $this->service->compare($a, $b, DuplicateField::ClosingLine);

        $this->assertFalse($result->originalExact);
        $this->assertFalse($result->normalizedExact);
    }

    public function testSyn004OneCharacterDifferenceOverlapCandidate(): void
    {
        $a = "轮流不是催姐姐让，而是让两个孩子都被听见。";
        $b = "轮流不是催姐姐让，而是让两个孩子都被听见了。";

        $result = $this->service->compare($a, $b, DuplicateField::ClosingLine);

        $this->assertFalse($result->originalExact);
        $this->assertFalse($result->normalizedExact);
        // Highly similar -> should be overlap candidate (closing_line threshold 0.4)
        $this->assertTrue($result->overlapCandidate, 'Expected overlap candidate, got score=' . $result->overlapScore);
    }

    public function testSemanticNewlinePreservedInExact(): void
    {
        $a = "第一行\n第二行";
        $b = "第一行 第二行";

        $result = $this->service->compare($a, $b, DuplicateField::PageTitle);

        $this->assertFalse($result->normalizedExact, 'Newline must be preserved in exact comparison');
    }

    public function testCaseDifferencePreservedInExact(): void
    {
        $a = "QN Culture";
        $b = "qn culture";

        $result = $this->service->compare($a, $b, DuplicateField::PageTitle);

        $this->assertFalse($result->normalizedExact);
    }

    public function testEmptyTexts(): void
    {
        $result = $this->service->compare("", "", DuplicateField::ClosingLine);

        // Both empty strings: original_exact can be true (string fact)
        $this->assertTrue($result->originalExact);
        // But overlap candidate must be false
        $this->assertFalse($result->overlapCandidate);
        $this->assertSame(0.0, $result->overlapScore);
    }

    public function testOneEmptyTextScoreZero(): void
    {
        $result = $this->service->compare("你好", "", DuplicateField::ClosingLine);

        $this->assertSame(0.0, $result->overlapScore);
        $this->assertFalse($result->overlapCandidate);
    }

    public function testDifferentFieldThresholds(): void
    {
        $textA = "测试文本相似度比较";
        $textB = "测试文本相似度对照";

        $closingResult = $this->service->compare($textA, $textB, DuplicateField::ClosingLine);
        $pageTitleResult = $this->service->compare($textA, $textB, DuplicateField::PageTitle);

        // Both should have same score, but different thresholds
        $this->assertSame($closingResult->overlapScore, $pageTitleResult->overlapScore);
        $this->assertNotSame($closingResult->threshold, $pageTitleResult->threshold);
    }
}
