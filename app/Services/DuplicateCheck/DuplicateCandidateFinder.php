<?php

namespace App\Services\DuplicateCheck;

/**
 * Finds duplicate candidates for a query entry against a corpus.
 *
 * Pure function: no DB, no HTTP. Reuses DuplicateCheckService.compare().
 */
final class DuplicateCandidateFinder
{
    public function __construct(
        private readonly DuplicateCheckService $service = new DuplicateCheckService,
    ) {}

    /**
     * @param  iterable<DuplicateComparableEntry>  $corpus
     * @return DuplicateCandidate[]
     */
    public function find(
        DuplicateComparableEntry $query,
        iterable $corpus,
        int $limit = 20,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Limit must be at least 1.');
        }

        $candidates = [];

        foreach ($corpus as $entry) {
            // Same-field only: never compare different field types
            if ($entry->field !== $query->field) {
                continue;
            }

            // Self exclusion: exact same identity
            if ($this->isSelf($query, $entry)) {
                continue;
            }

            $result = $this->service->compare(
                $query->text,
                $entry->text,
                $query->field,
                $query->slashLineBreakMarker,
                $entry->slashLineBreakMarker,
            );

            // Inclusion: only exact or overlap candidates
            if (! $result->originalExact && ! $result->normalizedExact && ! $result->overlapCandidate) {
                continue;
            }

            // Match kind priority: original > normalized > overlap
            $matchKind = match (true) {
                $result->originalExact => DuplicateCandidate::KIND_ORIGINAL_EXACT,
                $result->normalizedExact => DuplicateCandidate::KIND_NORMALIZED_EXACT,
                default => DuplicateCandidate::KIND_OVERLAP,
            };

            $candidates[] = new DuplicateCandidate(
                query: $query,
                match: $entry,
                originalExact: $result->originalExact,
                normalizedExact: $result->normalizedExact,
                overlapScore: $result->overlapScore,
                overlapCandidate: $result->overlapCandidate,
                threshold: $result->threshold,
                matchKind: $matchKind,
            );
        }

        // Sort: match_kind priority, then overlap_score DESC, then identity ASC
        usort($candidates, [$this, 'compareCandidates']);

        return array_slice($candidates, 0, $limit);
    }

    private function isSelf(DuplicateComparableEntry $a, DuplicateComparableEntry $b): bool
    {
        return $a->contentItemId === $b->contentItemId
            && $a->contentPageId === $b->contentPageId
            && $a->copyRevisionId === $b->copyRevisionId
            && $a->field === $b->field;
    }

    /**
     * Deterministic comparator.
     *
     * Order:
     *   1. match_kind priority (original_exact < normalized_exact < overlap)
     *   2. overlap_score DESC
     *   3. content_item_id ASC
     *   4. copy_revision_id ASC
     *   5. content_page_id ASC
     */
    private function compareCandidates(DuplicateCandidate $a, DuplicateCandidate $b): int
    {
        $kindOrder = [
            DuplicateCandidate::KIND_ORIGINAL_EXACT => 0,
            DuplicateCandidate::KIND_NORMALIZED_EXACT => 1,
            DuplicateCandidate::KIND_OVERLAP => 2,
        ];

        $ka = $kindOrder[$a->matchKind];
        $kb = $kindOrder[$b->matchKind];

        if ($ka !== $kb) {
            return $ka <=> $kb;
        }

        // Same kind: score DESC
        if ($a->overlapScore !== $b->overlapScore) {
            return $b->overlapScore <=> $a->overlapScore;
        }

        // Tie-break: per-field identity ASC
        $cmp = $this->compareIds($a->match->contentItemId, $b->match->contentItemId);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $this->compareIds($a->match->copyRevisionId, $b->match->copyRevisionId);
        if ($cmp !== 0) {
            return $cmp;
        }

        return $this->compareIds($a->match->contentPageId, $b->match->contentPageId);
    }

    /**
     * Compare two identity strings. If both are purely numeric, compare as
     * integers so that "2" < "10" (not "10" < "2" as raw string sort would do).
     * Otherwise fall back to string comparison.
     */
    private function compareIds(string $a, string $b): int
    {
        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);

        if ($aNumeric && $bNumeric) {
            return (int) $a <=> (int) $b;
        }

        return $a <=> $b;
    }
}
