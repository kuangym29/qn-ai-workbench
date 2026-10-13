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
 * 小绿书（image_post）分支的「人工编辑 / 保存当前正式内容」服务。
 *
 * 与 AI 导入（ImagePostImportService）共用同一套字段约束与版本快照语义，但入口不同：
 * AI 导入走 parse → preview → apply；人工编辑走 PUT 直接保存。
 *
 * 关键不变量：
 *   - PUT 不能简单覆盖历史：发生变化的页面才创建 ContentPageVersion 快照，未变化页面不产生无意义版本。
 *   - fixed_back_cover 受保护：既不能通过人工 PUT 修改其固定正式内容，也不能由 PUT 新增固定封底页。
 *   - 仅 image_post 分支可调用，其它格式一律拒绝。
 *   - 内部正式字段统一使用 core_message（UI 文案"核心表达"）。
 *
 * 字段范围严格限定为产品确认的 8 个页面字段 + 文章级 3 字段，不做任何"万能扩展"。
 */
class ImagePostManualService
{
    private const FIELDS = [
        'narrative_role', 'new_fact', 'final_copy',
        'visual_evidence', 'continuity', 'production_note',
    ];

    private const ITEM_FIELDS = ['title', 'core_message', 'story_line'];

    /**
     * 保存人工编辑结果。返回 warnings（目前用于 fixed_back_cover 被忽略的提示）。
     *
     * 语义：pages[] 为 upsert —— 已存在页码按内容差异更新并补版本，不存在页码新增；
     * 不在 payload 中的既有页面保持不动（不做破坏性删除）。
     */
    public function save(ContentBranch $branch, array $data): array
    {
        $this->assertImagePost($branch);
        $item = $branch->contentItem;
        $warnings = [];

        DB::transaction(function () use ($item, $data, &$warnings): void {
            // Serialize page creation and version numbering, including an empty page set.
            $item = ContentItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->applyItem($item, $data);

            $existing = $item->contentPages()->orderBy('page_no')->lockForUpdate()->get()->keyBy('page_no');
            foreach (($data['pages'] ?? []) as $page) {
                $pageNo = (int) $page['page_no'];
                $type = PageType::from($page['page_type']);

                // fixed_back_cover 保护：现有封底或试图新增封底都忽略，不修改、不新增、不补版本。
                $protected = $type === PageType::FixedBackCover
                    || ($existing->has($pageNo) && $existing->get($pageNo)->page_type === PageType::FixedBackCover);
                if ($protected) {
                    $warnings[] = [
                        'code' => 'fixed_back_cover_protected',
                        'page_no' => $pageNo,
                        'message' => '固定封底页受保护，未通过人工 PUT 修改。',
                    ];
                    continue;
                }

                $pageData = $this->pageData($page);
                if ($existing->has($pageNo)) {
                    $current = $existing->get($pageNo);
                    if ($this->changes($current, $pageData) === []) {
                        continue; // 未变化 → 不产生版本
                    }
                    $current->update($pageData);
                    $this->snapshot($item, $current, $pageData);
                } else {
                    $newPage = $item->contentPages()->create(
                        array_merge(['page_no' => $pageNo, 'project_id' => $item->project_id], $pageData)
                    );
                    $this->snapshot($item, $newPage, $pageData);
                }
            }
        }, 3);

        return $warnings;
    }

    private function pageData(array $page): array
    {
        $data = ['page_type' => PageType::from($page['page_type'])];
        foreach (self::FIELDS as $field) {
            $data[$field] = $page[$field] ?? null;
        }

        return $data;
    }

    private function changes(ContentPage $page, array $data): array
    {
        $changes = [];
        if ($page->page_type->value !== $data['page_type']->value) {
            $changes[] = ['field' => 'page_type', 'before' => $page->page_type->value, 'after' => $data['page_type']->value];
        }
        foreach (self::FIELDS as $field) {
            $before = (string) ($page->$field ?? '');
            $after = (string) ($data[$field] ?? '');
            if ($before !== $after) {
                $changes[] = ['field' => $field, 'before' => $page->$field, 'after' => $data[$field] ?? null];
            }
        }

        return $changes;
    }

    private function applyItem(ContentItem $item, array $data): void
    {
        $update = [];
        foreach (self::ITEM_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if ($update !== []) {
            $item->update($update);
        }
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

    private function assertImagePost(ContentBranch $branch): void
    {
        if ($branch->format !== ContentFormat::ImagePost) {
            throw new InvalidArgumentException('仅 image_post 分支支持图片文章编辑。');
        }
    }
}
