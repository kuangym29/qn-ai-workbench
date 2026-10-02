<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new Normalizer();
    }

    public function testCrlfConvertedToLf(): void
    {
        $result = $this->normalizer->canonicalize("line1\r\nline2");
        $this->assertSame("line1\nline2", $result);
    }

    public function testSlashMarkerOffByDefault(): void
    {
        // ／ should NOT be converted to newline when marker is false
        $result = $this->normalizer->canonicalize("你肯听，／情绪就有了出口。");
        $this->assertStringNotContainsString("\n", $result);
    }

    public function testSlashMarkerOnConvertsToNewline(): void
    {
        $result = $this->normalizer->canonicalize("你肯听，／情绪就有了出口。", slashLineBreakMarker: true);
        $this->assertSame("你肯听，\n情绪就有了出口。", $result);
    }

    public function testNfkcNormalizationExecuted(): void
    {
        // Full-width Ａ (U+FF21) should normalize to ASCII A (U+0041) under NFKC
        if (!class_exists(\Normalizer::class)) {
            $this->markTestSkipped('Normalizer class not available');
        }
        $result = $this->normalizer->exact("ＡＢＣ");
        $this->assertSame("ABC", $result);
    }

    public function testExactTrimsWhitespace(): void
    {
        $result = $this->normalizer->exact("  hello  ");
        $this->assertSame("hello", $result);
    }

    public function testExactCollapsesNonNewlineWhitespace(): void
    {
        $result = $this->normalizer->exact("hello   world");
        $this->assertSame("hello world", $result);
    }

    public function testExactPreservesSemanticNewline(): void
    {
        $result = $this->normalizer->exact("第一行\n第二行");
        $this->assertSame("第一行\n第二行", $result);
    }

    public function testExactPreservesChinesePunctuation(): void
    {
        // Chinese comma should NOT be converted to English comma
        $result = $this->normalizer->exact("你在身边，我自己来更有底气。");
        $this->assertStringContainsString("，", $result);
        $this->assertStringNotContainsString(",", $result);
    }

    public function testExactPreservesChineseQuotes(): void
    {
        $result = $this->normalizer->exact("你在身边，“我自己来”更有底气。");
        $this->assertStringContainsString("“", $result);
        $this->assertStringContainsString("”", $result);
    }

    public function testExactPreservesCaseDifference(): void
    {
        $result = $this->normalizer->exact("QN Culture");
        $this->assertSame("QN Culture", $result);
    }

    public function testOverlapConvertsNewlineToSpace(): void
    {
        $result = $this->normalizer->overlap("第一行\n第二行");
        $this->assertStringContainsString(" ", $result);
        $this->assertStringNotContainsString("\n", $result);
    }

    public function testOverlapRemovesPunctuation(): void
    {
        $result = $this->normalizer->overlap("你在身边，“我自己来”更有底气。");
        $this->assertStringNotContainsString("，", $result);
        $this->assertStringNotContainsString("。", $result);
        $this->assertStringNotContainsString("“", $result);
        $this->assertStringNotContainsString("”", $result);
        $this->assertStringContainsString("我自己来", $result);
    }
}
