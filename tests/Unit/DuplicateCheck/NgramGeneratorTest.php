<?php

namespace Tests\Unit\DuplicateCheck;

use App\Services\DuplicateCheck\NgramGenerator;
use PHPUnit\Framework\TestCase;

class NgramGeneratorTest extends TestCase
{
    public function testGeneratesTrigrams(): void
    {
        $ngrams = NgramGenerator::generate("你好世界", 3);
        // 5 chars -> 3 trigrams: 你好世, 好世界
        $this->assertCount(3, $ngrams);
        $this->assertSame("你好世", $ngrams[0]);
        $this->assertSame("好世界", $ngrams[1]);
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame([], NgramGenerator::generate(""));
    }

    public function testShortTextFallbackReturnsWholeString(): void
    {
        // 2 chars with n=3 -> returns whole string as single n-gram
        $ngrams = NgramGenerator::generate("你好", 3);
        $this->assertCount(1, $ngrams);
        $this->assertSame("你好", $ngrams[0]);
    }

    public function testUnicodeSafeNotByteBased(): void
    {
        // Chinese chars are 3 bytes each in UTF-8
        // If byte-based, we'd get garbage n-grams
        $ngrams = NgramGenerator::generate("青柠育见", 2);
        $this->assertSame("青柠", $ngrams[0]);
        $this->assertSame("柠育", $ngrams[1]);
        $this->assertSame("育见", $ngrams[2]);
    }
}
