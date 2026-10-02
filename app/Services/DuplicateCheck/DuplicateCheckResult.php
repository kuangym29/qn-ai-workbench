<?php

namespace App\Services\DuplicateCheck;

final class DuplicateCheckResult
{
    public function __construct(
        public readonly bool $originalExact,
        public readonly bool $normalizedExact,
        public readonly float $overlapScore,
        public readonly bool $overlapCandidate,
        public readonly float $threshold,
    ) {}
}
