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
        $this->normalizer = new Normalizer;
    }

    public function test_nfkc_runtime_available(): void
    {
        $this->assertTrue(class_exists(\Normalizer::class), 'intl Normalizer must be available in test environment');
    }

    public function test_crlf_converted_to_lf(): void
    {
        $result = $this->normalizer->canonicalize("line1\r\nline2");
        $this->assertSame("line1\nline2", $result);
    }

    public function test_slash_marker_off_by_default(): void
    {
        // ／ should NOT be converted to newline when marker is false
        $result = $this->normalizer->canonicalize('你肯听，／情绪就有了出口。');
        $this->assertStringNotContainsString("\n", $result);
    }

    public function test_slash_marker_on_converts_to_newline(): void
    {
        $result = $this->normalizer->canonicalize('你肯听，／情绪就有了出口。', slashLineBreakMarker: true);
        $this->assertSame("你肯听，\n情绪就有了出口。", $result);
    }

    public function test_nfkc_converts_fullwidth_letters(): void
    {
        // Fullwidth ＡＢＣ (U+FF21/U+FF22/U+FF23) are non-punctuation -> NFKC -> ASCII
        $result = $this->normalizer->exact('ＡＢＣ');
        $this->assertSame('ABC', $result);
    }

    public function test_nfkc_preserves_chinese_comma(): void
    {
        // Fullwidth comma ， (U+FF0C) is punctuation -> must NOT be converted to ASCII ,
        $result = $this->normalizer->exact('，');
        $this->assertSame('，', $result);
        $this->assertNotSame(',', $result);
    }

    public function test_nfkc_preserves_ascii_comma(): void
    {
        $result = $this->normalizer->exact(',');
        $this->assertSame(',', $result);
    }

    public function test_nfkc_preserves_fullwidth_slash_when_marker_off(): void
    {
        // ／ (U+FF0F) is punctuation -> NFKC must NOT convert it to / (U+002F)
        $result = $this->normalizer->exact('／', slashLineBreakMarker: false);
        $this->assertSame('／', $result);
        $this->assertNotSame('/', $result);
    }

    public function test_slash_marker_on_converts_slash_to_newline(): void
    {
        $result = $this->normalizer->exact('／', slashLineBreakMarker: true);
        $this->assertSame("\n", $result);
    }

    public function test_nfkc_preserves_chinese_quotes(): void
    {
        $result = $this->normalizer->exact('“测试”');
        $this->assertSame('“测试”', $result);
        $this->assertStringContainsString('“', $result);
        $this->assertStringContainsString('”', $result);
    }

    public function test_exact_trims_whitespace(): void
    {
        $result = $this->normalizer->exact('  hello  ');
        $this->assertSame('hello', $result);
    }

    public function test_exact_collapses_non_newline_whitespace(): void
    {
        $result = $this->normalizer->exact('hello   world');
        $this->assertSame('hello world', $result);
    }

    public function test_exact_preserves_semantic_newline(): void
    {
        $result = $this->normalizer->exact("第一行\n第二行");
        $this->assertSame("第一行\n第二行", $result);
    }

    public function test_exact_preserves_leading_and_trailing_semantic_newline(): void
    {
        // A semantic newline at either end is meaningful and must never be trimmed away.
        $result = $this->normalizer->exact("\n第一行\n");
        $this->assertSame("\n第一行\n", $result);
    }

    public function test_exact_trims_outer_spaces_but_keeps_semantic_newline(): void
    {
        // Outer ordinary whitespace is trimmed; the inner newlines are preserved.
        $result = $this->normalizer->exact("  \n第一行\n  ");
        $this->assertSame("\n第一行\n", $result);
    }

    public function test_exact_preserves_chinese_punctuation(): void
    {
        $result = $this->normalizer->exact('你在身边，我自己来更有底气。');
        $this->assertStringContainsString('，', $result);
        $this->assertStringNotContainsString(',', $result);
    }

    public function test_exact_preserves_case_difference(): void
    {
        $result = $this->normalizer->exact('QN Culture');
        $this->assertSame('QN Culture', $result);
    }

    public function test_overlap_converts_newline_to_space(): void
    {
        $result = $this->normalizer->overlap("第一行\n第二行");
        $this->assertStringContainsString(' ', $result);
        $this->assertStringNotContainsString("\n", $result);
    }

    public function test_overlap_removes_punctuation(): void
    {
        $result = $this->normalizer->overlap('你在身边，“我自己来”更有底气。');
        $this->assertStringNotContainsString('，', $result);
        $this->assertStringNotContainsString('。', $result);
        $this->assertStringNotContainsString('“', $result);
        $this->assertStringNotContainsString('”', $result);
        $this->assertStringContainsString('我自己来', $result);
    }
}
