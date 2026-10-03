<?php

namespace App\Services\DuplicateCheck;

final class JaccardSimilarity
{
    /**
     * Jaccard similarity of two n-gram sets.
     *
     * Returns value in [0.0, 1.0]. Empty input yields 0.0 (no NaN).
     *
     * @param  string[]  $ngramsA
     * @param  string[]  $ngramsB
     */
    public static function compute(array $ngramsA, array $ngramsB): float
    {
        if ($ngramsA === [] || $ngramsB === []) {
            return 0.0;
        }

        $setA = array_unique($ngramsA);
        $setB = array_unique($ngramsB);

        $intersection = count(array_intersect($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }
}
