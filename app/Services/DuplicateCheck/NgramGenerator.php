<?php

namespace App\Services\DuplicateCheck;

final class NgramGenerator
{
    /**
     * Generate Unicode-safe character n-grams.
     *
     * @return string[]
     */
    public static function generate(string $text, int $n = 3): array
    {
        if ($n < 1 || $text === '') {
            return [];
        }

        // Split into Unicode characters, not bytes
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (count($chars) < $n) {
            // Short-text fallback: return the whole string as a single n-gram
            // when text length is shorter than n. This is documented behavior.
            return [implode('', $chars)];
        }

        $ngrams = [];
        $len = count($chars);

        for ($i = 0; $i <= $len - $n; $i++) {
            $ngrams[] = implode('', array_slice($chars, $i, $n));
        }

        return $ngrams;
    }
}
