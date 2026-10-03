<?php

namespace App\Services\DuplicateCheck;

/**
 * Builds DuplicateComparableEntry[] from raw page snapshot arrays.
 *
 * Pure PHP: no Eloquent, no DB, no HTTP. The API layer is responsible
 * for converting DB rows into the expected array shape.
 */
final class DuplicateCorpusBuilder
{
    /**
     * Supported page_type -> field mapping.
     * fixed_back_cover yields no comparable fields by design.
     */
    private const PAGE_TYPE_FIELD_MAP = [
        'cover' => ['cover_title', 'cover_subtitle'],
        'content' => ['page_title', 'page_small_text'],
        'column_closing' => ['closing_line'],
        'fixed_back_cover' => [],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $pages
     *                                                   Each page array must contain keys:
     *                                                   - content_item_id: string
     *                                                   - content_page_id: string
     *                                                   - copy_revision_id: string
     *                                                   - page_no_snapshot: int
     *                                                   - page_type_snapshot: string
     *                                                   - cover_title: ?string
     *                                                   - cover_subtitle: ?string
     *                                                   - page_title: ?string
     *                                                   - page_small_text: ?string
     *                                                   - closing_line: ?string
     * @return DuplicateComparableEntry[]
     */
    public function build(array $pages): array
    {
        $entries = [];

        foreach ($pages as $page) {
            $pageType = $page['page_type_snapshot'] ?? '';

            if (! isset(self::PAGE_TYPE_FIELD_MAP[$pageType])) {
                continue;
            }

            $fields = self::PAGE_TYPE_FIELD_MAP[$pageType];

            foreach ($fields as $fieldKey) {
                $text = $page[$fieldKey] ?? null;

                // Skip null / empty text
                if ($text === null || trim($text) === '') {
                    continue;
                }

                $entries[] = new DuplicateComparableEntry(
                    contentItemId: $page['content_item_id'],
                    contentPageId: $page['content_page_id'],
                    copyRevisionId: $page['copy_revision_id'],
                    pageNo: (int) ($page['page_no_snapshot'] ?? 0),
                    pageType: $pageType,
                    field: $this->fieldFromKey($fieldKey),
                    text: $text,
                );
            }
        }

        return $entries;
    }

    private function fieldFromKey(string $key): DuplicateField
    {
        return match ($key) {
            'cover_title' => DuplicateField::CoverTitle,
            'cover_subtitle' => DuplicateField::CoverSubtitle,
            'page_title' => DuplicateField::PageTitle,
            'page_small_text' => DuplicateField::PageSmallText,
            'closing_line' => DuplicateField::ClosingLine,
            default => throw new \InvalidArgumentException("Unsupported comparable field: {$key}"),
        };
    }
}
