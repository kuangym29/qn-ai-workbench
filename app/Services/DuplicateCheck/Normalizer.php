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
     *
     * NFKC is applied ONLY to non-punctuation runs. Unicode punctuation
     * characters (including full-width commas, CJK quotes, ／) are preserved
     * as-is, because NFKC compatibility mapping would otherwise collapse
     * full-width punctuation into ASCII and break Exact punctuation fidelity.
     *
     * @throws \RuntimeException when intl Normalizer extension is unavailable.
     */
    public function exact(string $text, bool $slashLineBreakMarker = false): string
    {
        // Layer 1 first
        $text = $this->canonicalize($text, $slashLineBreakMarker);

        // NFKC fail-closed: must be available, no silent degradation
        if (! class_exists(\Normalizer::class)) {
            throw new \RuntimeException('NFKC normalizer runtime is unavailable.');
        }

        // Apply NFKC only to non-punctuation runs, preserve punctuation as-is
        $text = $this->nfkcPreservingPunctuation($text);

        // Trim leading/trailing NON-linebreak whitespace only.
        //
        // PHP's trim() also strips "\n", which silently destroyed semantic newlines:
        // exact("／", true) canonicalizes to "\n" and trim() then reduced it to "".
        // Semantic newlines are part of the exact representation and must survive.
        $text = preg_replace('/^[^\S\n]+/u', '', $text);
        $text = preg_replace('/[^\S\n]+$/u', '', $text);

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

    /**
     * Apply NFKC only to non-punctuation character runs.
     * Punctuation runs (\p{P}) are preserved byte-for-byte.
     *
     * This prevents NFKC compatibility mappings from converting:
     *   ， (U+FF0C fullwidth comma) -> , (U+002C)
     *   Ａ (U+FF21 fullwidth A)    -> A (U+0041)  [non-punctuation: normalized]
     *   “ ” (CJK quotes)            -> straight quotes [punctuation: preserved]
     *   ／ (U+FF0F fullwidth slash) -> / (U+002F)  [punctuation: preserved]
     */
    private function nfkcPreservingPunctuation(string $text): string
    {
        // Split by punctuation runs, keeping delimiters
        $parts = preg_split('/(\p{P}+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        $result = '';
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            // Even indices = non-punctuation runs -> apply NFKC
            // Odd indices = punctuation runs -> preserve as-is
            if ($i % 2 === 0) {
                $normalized = \Normalizer::normalize($part, \Normalizer::NFKC);
                $result .= $normalized !== false ? $normalized : $part;
            } else {
                $result .= $part;
            }
        }

        return $result;
    }
}
