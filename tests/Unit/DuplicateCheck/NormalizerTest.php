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

    public function testNfkcRuntimeAvailable(): void
    {
        $this->assertTrue(class_exists(\Normalizer::class), 'intl Normalizer must be available in test environment');
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

    public function testNfkcConvertsFullwidthLetters(): void
    {
        // Fullwidth ＡＢＣ (U+FF21/U+FF22/U+FF23) are non-punctuation -> NFKC -> ASCII
        $result = $this->normalizer->exact("ＡＢＣ");
        $this->assertSame("ABC", $result);
    }

    public function testNfkcPreservesChineseComma(): void
    {
        // Fullwidth comma ， (U+FF0C) is punctuation -> must NOT be converted to ASCII ,
        $result = $this->normalizer->exact("，");
        $this->assertSame("，", $result);
        $this->assertNotSame(",", $result);
    }

    public function testNfkcPreservesAsciiComma(): void
    {
        $result = $this->normalizer->exact(",");
        $this->assertSame(",", $result);
    }

    public function testNfkcPreservesFullwidthSlashWhenMarkerOff(): void
    {
        // ／ (U+FF0F) is punctuation -> NFKC must NOT convert it to / (U+002F)
        $result = $this->normalizer->exact("／", slashLineBreakMarker: false);
        $this->assertSame("／", $result);
        $this->assertNotSame("/", $result);
    }

    public function testSlashMarkerOnConvertsSlashToNewline(): void
    {
        $result = $this->normalizer->exact("／", slashLineBreakMarker: true);
        $this->assertSame("\n", $result);
    }

    public function testNfkcPreservesChineseQuotes(): void
    {
        $result = $this->normalizer->exact("“测试”");
        $this->assertSame("“测试”", $result);
        $this->assertStringContainsString("“", $result);
        $this->assertStringContainsString("”", $result);
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
        $result = $this->normalizer->exact("你在身边，我自己来更有底气。");
        $this->assertStringContainsString("，", $result);
        $this->assertStringNotContainsString(",", $result);
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
