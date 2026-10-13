<?php

namespace App\Services\V2;

use App\Enums\ContentFormat;
use App\Enums\PageType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 小绿书（image_post）AI 结构化导入的核心服务。
 *
 * 固定流程（数据只在确认后落库）：
 *   parse → normalize → validate → preview diff → user confirm → apply → 版本快照
 *
 * 关键不变量：
 *   - preview() 只读，绝不写入正式内容（对应"未 confirm 不得写入正式内容"）。
 *   - apply() 才落库，并为每个被应用页面保留一个版本快照。
 *   - fixed_back_cover 受保护：既不能被 AI 覆盖，也不能由 AI 新增。
 *   - 仅 image_post 分支可调用，其它格式一律拒绝。
 *
 * 字段范围严格限定为产品确认的 8 个页面字段 + 文章级 3 字段，
 * 不为"万能扩展"引入任何额外字段。
 */
class ImagePostImportService
{
    private const FIELDS = [
        'narrative_role', 'new_fact', 'final_copy',
        'visual_evidence', 'continuity', 'production_note',
    ];

    private const ITEM_FIELDS = ['title', 'core_message', 'story_line'];

    /**
     * 只读预览：返回 added / modified / unchanged / warnings，不触达写操作。
     */
    public function preview(ContentBranch $branch, array $parsed): array
    {
        $this->assertImagePost($branch);
        $this->assertStructure($parsed);
        $item = $branch->contentItem;
        $existing = $item->contentPages()->orderBy('page_no')->get()->keyBy('page_no');

        $added = [];
        $modified = [];
        $unchanged = [];
        $warnings = [];

        foreach ($parsed['pages'] as $page) {
            $pageNo = $page['page_no'];
            $type = PageType::from($page['page_type']);

            if ($type === PageType::FixedBackCover) {
                $warnings[] = [
                    'code' => 'fixed_back_cover_rejected',
                    'page_no' => $pageNo,
                    'message' => '固定封底页不可由 AI 导入新增或覆盖，已跳过。',
                ];
                continue;
            }

            if ($existing->has($pageNo)) {
                $current = $existing->get($pageNo);
                if ($current->page_type === PageType::FixedBackCover) {
                    $warnings[] = [
                        'code' => 'fixed_back_cover_protected',
                        'page_no' => $pageNo,
                        'message' => '该页为固定封底，受保护，未被修改。',
                    ];
                    continue;
                }
                $changes = $this->fieldChanges($current, $page, $type);
                if ($changes === []) {
                    $unchanged[] = ['page_no' => $pageNo, 'page_type' => $type->value];
                } else {
                    $modified[] = ['page_no' => $pageNo, 'page_type' => $type->value, 'changes' => $changes];
                }
            } else {
                $added[] = [
                    'page_no' => $pageNo,
                    'page_type' => $type->value,
                    'narrative_role' => $page['narrative_role'] ?? null,
                    'new_fact' => $page['new_fact'] ?? null,
                    'final_copy' => $page['final_copy'] ?? null,
                    'visual_evidence' => $page['visual_evidence'] ?? null,
                    'continuity' => $page['continuity'] ?? null,
                    'production_note' => $page['production_note'] ?? null,
                ];
            }
        }

        return [
            'item' => $this->itemChanges($item, $parsed['item'] ?? []),
            'pages' => ['added' => $added, 'modified' => $modified, 'unchanged' => $unchanged],
            'warnings' => $warnings,
        ];
    }

    /**
     * 确认应用：落库并保留版本。onlyPageNos 非空时仅应用指定页码（局部更新）。
     */
    public function apply(ContentBranch $branch, array $parsed, ?array $onlyPageNos = null): void
    {
        $this->assertImagePost($branch);
        $this->assertStructure($parsed);
        $item = $branch->contentItem;

        DB::transaction(function () use ($item, $parsed, $onlyPageNos): void {
            $this->applyItem($item, $parsed['item'] ?? []);

            $existing = $item->contentPages()->orderBy('page_no')->lockForUpdate()->get()->keyBy('page_no');
            foreach ($parsed['pages'] as $page) {
                $pageNo = $page['page_no'];
                if ($onlyPageNos !== null && ! in_array($pageNo, $onlyPageNos, true)) {
                    continue;
                }
                $type = PageType::from($page['page_type']);
                if ($type === PageType::FixedBackCover) {
                    continue;
                }
                if ($existing->has($pageNo)) {
                    $current = $existing->get($pageNo);
                    if ($current->page_type === PageType::FixedBackCover) {
                        continue;
                    }
                }

                $data = $this->pageData($page, $type);
                if ($existing->has($pageNo)) {
                    $page = $existing->get($pageNo);
                    $page->update($data);
                } else {
                    $page = $item->contentPages()->create(
                        array_merge(['page_no' => $pageNo, 'project_id' => $item->project_id], $data)
                    );
                }

                $this->snapshot($item, $page, $data);
            }
        }, 3);
    }

    private function applyItem(ContentItem $item, array $itemData): void
    {
        $update = [];
        foreach (self::ITEM_FIELDS as $field) {
            if (array_key_exists($field, $itemData) && $itemData[$field] !== null) {
                $update[$field] = $itemData[$field];
            }
        }
        if ($update !== []) {
            $item->update($update);
        }
    }

    private function pageData(array $page, PageType $type): array
    {
        $data = ['page_type' => $type];
        foreach (self::FIELDS as $field) {
            $data[$field] = $page[$field] ?? null;
        }

        return $data;
    }

    private function snapshot(ContentItem $item, ContentPage $page, array $data): void
    {
        $max = (int) $page->versions()->max('version_no');
        $page->versions()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'version_no' => $max + 1,
            'page_no_snapshot' => $page->page_no,
            'page_type_snapshot' => $page->page_type->value,
            ...Arr::only($data, self::FIELDS),
        ]);
    }

    /**
     * modified 至少表达：page_no / field / before / after。
     */
    private function fieldChanges(ContentPage $page, array $parsed, PageType $type): array
    {
        $changes = [];
        if ($page->page_type->value !== $type->value) {
            $changes[] = ['field' => 'page_type', 'before' => $page->page_type->value, 'after' => $type->value];
        }
        foreach (self::FIELDS as $field) {
            $before = (string) ($page->$field ?? '');
            $after = (string) ($parsed[$field] ?? '');
            if ($before !== $after) {
                $changes[] = ['field' => $field, 'before' => $page->$field, 'after' => $parsed[$field] ?? null];
            }
        }

        return $changes;
    }

    private function itemChanges(ContentItem $item, array $itemData): array
    {
        $changed = false;
        $fields = [];
        foreach (self::ITEM_FIELDS as $field) {
            if (array_key_exists($field, $itemData) && $itemData[$field] !== null) {
                $current = (string) ($item->$field ?? '');
                $incoming = (string) $itemData[$field];
                if ($current !== $incoming) {
                    $changed = true;
                    $fields[] = ['field' => $field, 'before' => $item->$field, 'after' => $itemData[$field]];
                }
            }
        }

        return ['changed' => $changed, 'fields' => $fields];
    }

    private function assertImagePost(ContentBranch $branch): void
    {
        if ($branch->format !== ContentFormat::ImagePost) {
            throw new InvalidArgumentException('仅 image_post 分支支持图片文章 AI 导入。');
        }
    }

    private function assertStructure(array $parsed): void
    {
        if (! isset($parsed['pages']) || ! is_array($parsed['pages']) || count($parsed['pages']) === 0) {
            throw new InvalidArgumentException('AI 导入结构缺少有效的 pages。');
        }
        foreach ($parsed['pages'] as $page) {
            if (! isset($page['page_no']) || ! isset($page['page_type'])) {
                throw new InvalidArgumentException('存在缺少页码或页面类型的页面。');
            }
            if (! PageType::tryFrom($page['page_type']) instanceof PageType) {
                throw new InvalidArgumentException("页面类型「{$page['page_type']}」无效。");
            }
        }
    }
}
