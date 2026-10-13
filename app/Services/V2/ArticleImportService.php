<?php

namespace App\Services\V2;

use App\Enums\ContentFormat;
use App\Models\ArticleContent;
use App\Models\ArticleContentVersion;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 公众号 · 图文文章（article）AI 结构化导入核心服务。
 *
 * 固定流程（数据只在确认后落库）：
 *   parse → normalize → validate → preview diff → user confirm → apply → 版本快照
 *
 * 关键不变量：
 *   - preview() 只读，绝不写入正式内容（对应"未 confirm 不得写入正式内容"）。
 *   - apply() 才落库，并为真正发生变化的文章保留一个版本快照。
 *   - 完全 unchanged 不创建无意义版本。
 *   - 仅 article 分支可调用，其它格式一律拒绝。
 *
 * 字段范围严格限定为产品确认的 5 个文章字段 + 文章级 3 共享字段，不做"万能扩展"。
 */
class ArticleImportService
{
    private const FIELDS = ['headline', 'summary', 'body', 'cover_suggestion', 'image_suggestions'];

    private const ITEM_FIELDS = ['title', 'core_message', 'story_line'];

    public function preview(ContentBranch $branch, array $parsed): array
    {
        $this->assertArticle($branch);
        $this->assertStructure($parsed);
        $content = ArticleContent::where('content_branch_id', $branch->id)->first();

        $added = [];
        $modified = [];
        $unchanged = [];

        foreach (self::FIELDS as $field) {
            $before = $content?->$field;
            $after = $parsed['article'][$field] ?? null;

            if (! array_key_exists($field, $parsed['article'])) {
                if ($content !== null && $before !== null) {
                    $unchanged[] = ['field' => $field];
                }
                continue;
            }
            if ($content === null) {
                // 首导入：article 从无到有，提供的字段标记为 added。
                $added[] = ['field' => $field, 'after' => $after];
            } elseif (! $this->valuesEqual($before, $after)) {
                $modified[] = ['field' => $field, 'before' => $before, 'after' => $after];
            } else {
                $unchanged[] = ['field' => $field];
            }
        }

        return [
            'item' => $this->itemChanges($branch->contentItem, $parsed['item'] ?? []),
            'article' => ['added' => $added, 'modified' => $modified, 'unchanged' => $unchanged],
            'warnings' => [],
        ];
    }

    public function apply(ContentBranch $branch, array $parsed): void
    {
        $this->assertArticle($branch);
        $this->assertStructure($parsed);
        $item = $branch->contentItem;

        DB::transaction(function () use ($item, $branch, $parsed): void {
            $this->applyItem($item, $parsed['item'] ?? []);

            $content = ArticleContent::where('content_branch_id', $branch->id)->first();
            if ($content === null) {
                $content = new ArticleContent([
                    'project_id' => $item->project_id,
                    'content_item_id' => $item->id,
                    'content_branch_id' => $branch->id,
                ]);
            }

            $data = [];
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $parsed['article']) && $parsed['article'][$field] !== null) {
                    $data[$field] = $parsed['article'][$field];
                }
            }

            $changed = false;
            foreach ($data as $field => $value) {
                if (! $this->valuesEqual($content->$field, $value)) {
                    $changed = true;
                }
                $content->$field = $value;
            }

            if ($data !== []) {
                $content->save();
            }

            if ($changed) {
                $this->snapshot($content);
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

    private function snapshot(ArticleContent $content): void
    {
        $max = (int) $content->versions()->max('version_no');
        $content->versions()->create([
            'version_no' => $max + 1,
            'snapshot' => [
                'headline' => $content->headline,
                'summary' => $content->summary,
                'body' => $content->body,
                'cover_suggestion' => $content->cover_suggestion,
                'image_suggestions' => $content->image_suggestions,
            ],
        ]);
    }

    private function valuesEqual(mixed $before, mixed $after): bool
    {
        if (is_array($before) || is_array($after)) {
            return json_encode(array_map('self::normalize', (array) $before))
                === json_encode(array_map('self::normalize', (array) $after));
        }

        return (string) ($before ?? '') === (string) ($after ?? '');
    }

    private static function normalize(mixed $value): mixed
    {
        return is_array($value) ? array_map('self::normalize', $value) : $value;
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

    private function assertArticle(ContentBranch $branch): void
    {
        if ($branch->format !== ContentFormat::Article) {
            throw new InvalidArgumentException('仅 article 分支支持图文文章 AI 导入。');
        }
    }

    private function assertStructure(array $parsed): void
    {
        if (! isset($parsed['article']) || ! is_array($parsed['article'])) {
            throw new InvalidArgumentException('AI 导入结构缺少有效的 article。');
        }
        if (isset($parsed['article']['image_suggestions']) && ! is_array($parsed['article']['image_suggestions'])) {
            throw new InvalidArgumentException('image_suggestions 必须为数组。');
        }
    }
}
