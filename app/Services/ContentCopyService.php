<?php

namespace App\Services;

use App\Enums\CopyStatus;
use App\Enums\PageType;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContentCopyService
{
    private const COPY_FIELDS = [
        'column_label', 'cover_title', 'cover_subtitle', 'page_title',
        'page_small_text', 'closing_line', 'note',
    ];

    public function appendDraft(ContentPage $page, array $copy): ContentPageVersion
    {
        if (array_diff(array_keys($copy), self::COPY_FIELDS)) {
            throw new InvalidArgumentException('Unknown page copy field.');
        }

        return DB::transaction(function () use ($page, $copy): ContentPageVersion {
            $item = ContentItem::query()->whereKey($page->content_item_id)->lockForUpdate()->firstOrFail();
            $lockedPage = $item->contentPages()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $current = $lockedPage->versions()->orderByDesc('version_no')->first();
            $data = array_fill_keys(self::COPY_FIELDS, null);
            if ($current) {
                $data = $current->only(self::COPY_FIELDS);
            }

            $version = $lockedPage->versions()->forceCreate([
                'project_id' => $item->project_id,
                'content_item_id' => $item->id,
                'version_no' => ($current?->version_no ?? 0) + 1,
                'copy_revision_id' => null,
                'page_no_snapshot' => null,
                'page_type_snapshot' => null,
                ...$data,
                ...Arr::only($copy, self::COPY_FIELDS),
            ]);
            $item->update(['copy_status' => CopyStatus::Editing]);

            return $version;
        }, 3);
    }

    public function confirmContentItem(ContentItem $item): ContentCopyRevision
    {
        return DB::transaction(function () use ($item): ContentCopyRevision {
            $lockedItem = ContentItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $pages = $lockedItem->contentPages()->orderBy('page_no')->lockForUpdate()->get();
            if ($pages->isEmpty()) {
                throw new InvalidArgumentException('At least one page is required.');
            }

            $latest = [];
            foreach ($pages as $index => $page) {
                if ($page->page_no !== $index + 1) {
                    throw new InvalidArgumentException('Page numbers must be continuous from 1.');
                }
                $type = PageType::tryFrom($page->getRawOriginal('page_type'));
                if ($type === null) {
                    throw new InvalidArgumentException('Invalid page type.');
                }
                $version = $page->versions()->orderByDesc('version_no')->first();
                if ($version === null) {
                    throw new InvalidArgumentException('Every page needs a copy version.');
                }
                $this->validateCopy($type, $version);
                $latest[$page->id] = $version;
            }

            $revision = $lockedItem->contentCopyRevisions()->forceCreate([
                'project_id' => $lockedItem->project_id,
                'revision_no' => ((int) $lockedItem->contentCopyRevisions()->max('revision_no')) + 1,
                'confirmed_at' => now(),
            ]);

            foreach ($pages as $page) {
                $current = $latest[$page->id];
                $page->versions()->forceCreate([
                    'project_id' => $lockedItem->project_id,
                    'content_item_id' => $lockedItem->id,
                    'copy_revision_id' => $revision->id,
                    'version_no' => $current->version_no + 1,
                    'page_no_snapshot' => $page->page_no,
                    'page_type_snapshot' => $page->page_type->value,
                    ...$current->only(self::COPY_FIELDS),
                ]);
            }

            $lockedItem->update(['copy_status' => CopyStatus::Confirmed]);

            return $revision;
        }, 3);
    }

    public function reorderPages(ContentItem $item, array $pageIds): void
    {
        DB::transaction(function () use ($item, $pageIds): void {
            $lockedItem = ContentItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $pages = $lockedItem->contentPages()->orderBy('id')->lockForUpdate()->get();
            $actual = $pages->pluck('id')->all();
            $requested = $pageIds;
            sort($actual);
            sort($requested);
            if ($pages->isEmpty() || $actual !== $requested || count($pageIds) !== count(array_unique($pageIds))) {
                throw new InvalidArgumentException('Page order must contain every page of this ContentItem exactly once.');
            }

            $max = $pages->max('page_no');
            if ($max > PHP_INT_MAX - $pages->count()) {
                throw new InvalidArgumentException('No safe temporary page number range is available.');
            }
            $byId = $pages->keyBy('id');
            foreach ($pageIds as $index => $id) {
                $byId[$id]->update(['page_no' => $max + $index + 1]);
            }
            foreach ($pageIds as $index => $id) {
                $byId[$id]->update(['page_no' => $index + 1]);
            }
            if ($lockedItem->copy_status === CopyStatus::Confirmed) {
                $lockedItem->update(['copy_status' => CopyStatus::Editing]);
            }
        }, 3);
    }

    private function validateCopy(PageType $type, ContentPageVersion $version): void
    {
        $hasText = static fn (?string $value): bool => $value !== null && trim($value) !== '';
        $fields = ['column_label', 'cover_title', 'cover_subtitle', 'page_title', 'page_small_text', 'closing_line'];
        $allowed = match ($type) {
            PageType::Cover => ['column_label', 'cover_title', 'cover_subtitle'],
            PageType::Content => ['page_title', 'page_small_text'],
            PageType::ColumnClosing => ['closing_line'],
            PageType::FixedBackCover => [],
        };
        foreach (array_diff($fields, $allowed) as $field) {
            if ($hasText($version->$field)) {
                throw new InvalidArgumentException("{$field} is not valid for this page type.");
            }
        }
        if ($type !== PageType::FixedBackCover && ! collect($allowed)->contains(fn (string $field): bool => $hasText($version->$field))) {
            throw new InvalidArgumentException('This page needs copy appropriate to its type.');
        }
    }
}
