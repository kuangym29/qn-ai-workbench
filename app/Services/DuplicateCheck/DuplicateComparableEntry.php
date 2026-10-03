<?php

namespace App\Services\DuplicateCheck;

/**
 * A comparable text entry in the duplicate-check corpus.
 *
 * Represents one field of one page snapshot. The finder does not know
 * whether this comes from Formal Copy, Working Copy, or a historical revision.
 */
final class DuplicateComparableEntry
{
    public function __construct(
        public readonly string $contentItemId,
        public readonly string $contentPageId,
        public readonly string $copyRevisionId,
        public readonly int $pageNo,
        public readonly string $pageType,
        public readonly DuplicateField $field,
        public readonly string $text,
        public readonly bool $slashLineBreakMarker = false,
    ) {}
}
