<?php

namespace App\Services\DuplicateCheck;

/**
 * Pure-function duplicate check service.
 *
 * No database queries, no HTTP calls, no vector DB.
 * Suitable for unit testing and future API integration.
 */
final class DuplicateCheckService
{
    public function __construct(
        private readonly Normalizer $normalizer = new Normalizer,
    ) {}

    public function compare(
        string $left,
        string $right,
        DuplicateField $field,
        bool $leftSlashLineBreakMarker = false,
        bool $rightSlashLineBreakMarker = false,
    ): DuplicateCheckResult {
        // Original exact: byte-for-byte equality
        $originalExact = ($left === $right);

        // Normalized exact: after Exact Normalization (Layer 1 + Layer 2)
        $normLeft = $this->normalizer->exact($left, $leftSlashLineBreakMarker);
        $normRight = $this->normalizer->exact($right, $rightSlashLineBreakMarker);
        $normalizedExact = ($normLeft === $normRight);

        // Overlap score: using Overlap Normalization (Layer 3) + 3-gram Jaccard
        $overlapLeft = $this->normalizer->overlap($left, $leftSlashLineBreakMarker);
        $overlapRight = $this->normalizer->overlap($right, $rightSlashLineBreakMarker);

        // Short-text fallback: use n = min(3, min char length of both sides)
        $charLenLeft = mb_strlen($overlapLeft, 'UTF-8');
        $charLenRight = mb_strlen($overlapRight, 'UTF-8');
        $minLen = min($charLenLeft, $charLenRight);
        $n = $minLen < 3 ? max(1, $minLen) : 3;

        $ngramsA = NgramGenerator::generate($overlapLeft, $n);
        $ngramsB = NgramGenerator::generate($overlapRight, $n);
        $score = JaccardSimilarity::compute($ngramsA, $ngramsB);

        $threshold = $field->threshold();

        // Overlap candidate: NOT normalized exact AND score >= threshold
        // Exact duplicates are never marked as mere candidates.
        $overlapCandidate = (! $normalizedExact) && ($score >= $threshold);

        return new DuplicateCheckResult(
            originalExact: $originalExact,
            normalizedExact: $normalizedExact,
            overlapScore: $score,
            overlapCandidate: $overlapCandidate,
            threshold: $threshold,
        );
    }
}
