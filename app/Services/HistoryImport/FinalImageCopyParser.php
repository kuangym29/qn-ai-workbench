<?php

namespace App\Services\HistoryImport;

use App\Enums\PageType;
use RuntimeException;

class FinalImageCopyParser
{
    private const COPY_FIELDS = [
        'column_label', 'cover_title', 'cover_subtitle',
        'page_title', 'page_small_text', 'closing_line', 'note',
    ];

    public function parse(string $markdown, string $title, string $columnName, array $expectedTypes): array
    {
        $lines = preg_split('/\R/u', $markdown);
        $heading = '## 《'.$title.'》';
        $matches = [];
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $heading)) {
                $matches[] = $index;
            }
        }
        if (count($matches) !== 1 || ! preg_match('/^## 《'.preg_quote($title, '/').'》｜[^\r\n]+$/u', $lines[$matches[0]])) {
            throw new RuntimeException("IMPORT_ABORT: expected exactly one section for {$title}.");
        }

        $section = array_slice($lines, $matches[0] + 1);
        foreach ($section as $index => $line) {
            if (str_starts_with($line, '## ')) {
                $section = array_slice($section, 0, $index);
                break;
            }
        }
        $header = '| 页 | 类型 | 标题／主文案 | 副标题／横线下小字 |';
        $headerIndexes = array_keys(array_filter($section, fn (string $line): bool => $line === $header));
        if (count($headerIndexes) !== 1) {
            throw new RuntimeException("IMPORT_ABORT: missing or duplicate page table for {$title}.");
        }
        $start = $headerIndexes[0];
        if (! isset($section[$start + 1]) || ! preg_match('/^\|\s*-+\s*\|\s*-+\s*\|\s*-+\s*\|\s*-+\s*\|$/u', $section[$start + 1])) {
            throw new RuntimeException("IMPORT_ABORT: invalid page table separator for {$title}.");
        }

        $pages = [];
        foreach (array_slice($section, $start + 2) as $line) {
            if (! str_starts_with($line, '|')) {
                break;
            }
            $cells = explode('|', $line);
            if (count($cells) !== 6 || $cells[0] !== '' || $cells[5] !== '') {
                throw new RuntimeException("IMPORT_ABORT: unknown page row structure for {$title}.");
            }
            $cells = array_map(fn (string $cell): string => trim($cell, " \t"), array_slice($cells, 1, 4));
            if (! preg_match('/^\d{2}$/', $cells[0])) {
                throw new RuntimeException("IMPORT_ABORT: invalid page number in {$title}.");
            }
            $number = (int) $cells[0];
            if ($number !== count($pages) + 1 || ! isset($expectedTypes[$number - 1])) {
                throw new RuntimeException("IMPORT_ABORT: duplicate, missing or extra page {$number} in {$title}.");
            }
            [$type, $columnLabel] = $this->type($cells[1], $columnName);
            if ($type !== $expectedTypes[$number - 1]) {
                throw new RuntimeException("IMPORT_ABORT: page type mismatch in {$title} page {$number}.");
            }
            $copy = array_fill_keys(self::COPY_FIELDS, null);
            if ($type === PageType::Cover) {
                $copy['column_label'] = $columnLabel;
                $copy['cover_title'] = $this->text($cells[2]);
                $copy['cover_subtitle'] = $this->text($cells[3]);
            } elseif ($type === PageType::Content) {
                $copy['page_title'] = $this->text($cells[2]);
                $copy['page_small_text'] = $this->text($cells[3]);
            } elseif ($type === PageType::ColumnClosing) {
                if ($cells[3] !== '—') {
                    throw new RuntimeException("IMPORT_ABORT: unexpected closing subtitle in {$title} page {$number}.");
                }
                $copy['closing_line'] = $this->text($cells[2]);
            } else {
                if ($cells[2] !== '调用用户原稿；无本篇新增文案' || $cells[3] !== '—') {
                    throw new RuntimeException("IMPORT_ABORT: unknown back cover format in {$title} page {$number}.");
                }
            }
            if ($type !== PageType::FixedBackCover && $this->primaryText($copy, $type) === null) {
                throw new RuntimeException("IMPORT_ABORT: missing copy in {$title} page {$number}.");
            }
            $pages[] = ['page_no' => $number, 'page_type' => $type, 'copy' => $copy];
        }
        if (count($pages) !== count($expectedTypes)) {
            throw new RuntimeException('IMPORT_ABORT: expected '.count($expectedTypes)." pages for {$title}, parsed ".count($pages).'.');
        }

        return $pages;
    }

    private function type(string $raw, string $columnName): array
    {
        if (preg_match('/^封面，栏目「(.+)」$/u', $raw, $match)) {
            if ($match[1] !== $columnName) {
                throw new RuntimeException("IMPORT_ABORT: cover column label mismatch: {$raw}.");
            }

            return [PageType::Cover, $match[1]];
        }

        return match ($raw) {
            '正文' => [PageType::Content, null],
            '栏目收尾，历史图文版', '本篇专属栏目收尾' => [PageType::ColumnClosing, null],
            '固定封底' => [PageType::FixedBackCover, null],
            default => throw new RuntimeException("IMPORT_ABORT: unknown page type {$raw}."),
        };
    }

    private function text(string $raw): ?string
    {
        if ($raw === '—') {
            return null;
        }
        if ($raw === '') {
            throw new RuntimeException('IMPORT_ABORT: empty copy field.');
        }

        return str_replace('／', "\n", $raw);
    }

    private function primaryText(array $copy, PageType $type): ?string
    {
        return match ($type) {
            PageType::Cover => $copy['cover_title'],
            PageType::Content => $copy['page_title'],
            PageType::ColumnClosing => $copy['closing_line'],
            PageType::FixedBackCover => null,
        };
    }
}
