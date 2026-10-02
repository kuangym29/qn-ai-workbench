<?php

namespace App\Services\DuplicateCheck;

final class Normalizer
{
    /**
     * Layer 1: Source Representation Canonicalization.
     *
     * Reduces explicit format differences across source files into the same
     * semantic structure. This is NOT fuzzy matching — it only handles
     * representation differences that the source format itself declares.
     */
    public function canonicalize(string $text, bool $slashLineBreakMarker = false): string
    {
        // CRLF / CR -> LF
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        // Optional: source-declared ／ as explicit line break -> \n
        if ($slashLineBreakMarker) {
            $text = str_replace('／', "\n", $text);
        }

        return $text;
    }

    /**
     * Layer 2: Exact Normalization.
     *
     * Conservative transformations only. Preserves semantic newlines,
     * all punctuation, and quote-style differences.
     */
    public function exact(string $text, bool $slashLineBreakMarker = false): string
    {
        // Layer 1 first
        $text = $this->canonicalize($text, $slashLineBreakMarker);

        // Unicode NFKC compatibility normalization
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::NFKC);
            if ($normalized !== false) {
                $text = $normalized;
            }
        }

        // Trim leading/trailing whitespace
        $text = trim($text);

        // Collapse consecutive non-newline whitespace into single space
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);

        return $text;
    }

    /**
     * Layer 3: Overlap Normalization.
     *
     * Aggressive simplification for candidate discovery only.
     * MUST NOT be used for normalized_exact determination.
     */
    public function overlap(string $text, bool $slashLineBreakMarker = false): string
    {
        // Start from exact normalization
        $text = $this->exact($text, $slashLineBreakMarker);

        // Semantic newline -> space
        $text = str_replace("\n", ' ', $text);

        // Remove all Unicode punctuation (including Chinese/English quotes)
        $text = preg_replace('/\p{P}/u', '', $text);

        // Collapse whitespace
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
