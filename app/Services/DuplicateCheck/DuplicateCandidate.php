<?php

namespace App\Services\DuplicateCheck;

/**
 * A single duplicate candidate result.
 */
final class DuplicateCandidate
{
    public const KIND_ORIGINAL_EXACT = 'original_exact';
    public const KIND_NORMALIZED_EXACT = 'normalized_exact';
    public const KIND_OVERLAP = 'overlap';

    public function __construct(
        public readonly DuplicateComparableEntry $query,
        public readonly DuplicateComparableEntry $match,
        public readonly bool $originalExact,
        public readonly bool $normalizedExact,
        public readonly float $overlapScore,
        public readonly bool $overlapCandidate,
        public readonly float $threshold,
        public readonly string $matchKind,
    ) {}
}
