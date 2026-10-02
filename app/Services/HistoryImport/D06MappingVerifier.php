<?php

namespace App\Services\HistoryImport;

use RuntimeException;

class D06MappingVerifier
{
    private const FIELDS = [
        'column_label', 'cover_title', 'cover_subtitle',
        'page_title', 'page_small_text', 'closing_line',
    ];

    public function verify(string $mapping, string $title, array $canonical): void
    {
        $lines = preg_split('/\R/u', $mapping);
        $headings = array_keys(array_filter($lines, fn (string $line): bool => preg_match(
            '/^### 篇 \d+：《'.preg_quote($title, '/').'》/u', $line
        ) === 1));
        if (count($headings) !== 1) {
            throw new RuntimeException("IMPORT_ABORT: D06 section missing or ambiguous for {$title}.");
        }
        $section = array_slice($lines, $headings[0] + 1);
        foreach ($section as $index => $line) {
            if (str_starts_with($line, '### ')) {
                $section = array_slice($section, 0, $index);
                break;
            }
        }
        $header = '| page_no | page_type | column_label | cover_title | cover_subtitle | page_title | page_small_text | closing_line | note |';
        $headers = array_keys(array_filter($section, fn (string $line): bool => $line === $header));
        if (count($headers) !== 1) {
            throw new RuntimeException("IMPORT_ABORT: D06 table missing or ambiguous for {$title}.");
        }
        $rows = [];
        foreach (array_slice($section, $headers[0] + 2) as $line) {
            if (! str_starts_with($line, '|')) {
                break;
            }
            $cells = explode('|', $line);
            if (count($cells) !== 11 || $cells[0] !== '' || $cells[10] !== '') {
                throw new RuntimeException("IMPORT_ABORT: invalid D06 row for {$title}.");
            }
            $cells = array_map(fn (string $cell): string => trim($cell, " \t"), array_slice($cells, 1, 9));
            if (! ctype_digit($cells[0]) || isset($rows[(int) $cells[0]])) {
                throw new RuntimeException("IMPORT_ABORT: invalid or duplicate D06 page for {$title}.");
            }
            $rows[(int) $cells[0]] = $cells;
        }
        if (count($rows) !== count($canonical)) {
            throw new RuntimeException("IMPORT_ABORT: D06 page count mismatch for {$title}.");
        }

        foreach ($canonical as $page) {
            $number = $page['page_no'];
            if (! isset($rows[$number])) {
                throw new RuntimeException("IMPORT_ABORT: D06 missing {$title} page {$number}.");
            }
            $row = $rows[$number];
            $this->compare($title, $number, 'page_type', $page['page_type']->value, $row[1]);
            foreach (self::FIELDS as $index => $field) {
                $expected = $row[$index + 2] === 'null' ? null : str_replace('\\n', "\n", $row[$index + 2]);
                $this->compare($title, $number, $field, $page['copy'][$field], $expected);
            }
        }
    }

    private function compare(string $title, int $pageNo, string $field, ?string $canonical, ?string $expected): void
    {
        if ($canonical !== $expected) {
            $left = json_encode($canonical, JSON_UNESCAPED_UNICODE);
            $right = json_encode($expected, JSON_UNESCAPED_UNICODE);
            throw new RuntimeException(
                "IMPORT_ABORT: item={$title} page_no={$pageNo} field={$field} canonical={$left} D06_expected={$right}"
            );
        }
    }
}
