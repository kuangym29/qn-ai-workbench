<?php

namespace App\Services\V2;

use App\Enums\ContentFormat;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 公众号 · 图文文章（article）分支的「人工编辑 / 保存当前正式内容」服务。
 *
 * 与 AI 导入（ArticleImportService）共用字段约束与版本快照语义，但入口不同：
 * AI 导入走 parse → preview → apply；人工编辑走 PUT 直接保存。
 *
 * 关键不变量：
 *   - PUT 不能简单覆盖历史：真正发生变化的文章才创建版本快照，未变化不产生无意义版本。
 *   - 仅 article 分支可调用，其它格式一律拒绝。
 *   - 内部正式字段统一使用 core_message（UI 文案"核心表达"）；不存在 core_expression 数据字段。
 *
 * 字段范围严格限定为产品确认的 5 个文章字段 + 文章级 3 共享字段，不做"万能扩展"。
 */
class ArticleManualService
{
    private const FIELDS = ['headline', 'summary', 'body', 'cover_suggestion', 'image_suggestions'];

    private const ITEM_FIELDS = ['title', 'core_message', 'story_line'];

    /**
     * 读取当前正式内容：文章级 3 字段 + 分支 2 字段 + 文章正式内容（无则 null）。
     */
    public function get(ContentBranch $branch): array
    {
        $item = $branch->contentItem;
        $content = ArticleContent::where('content_branch_id', $branch->id)->first();

        return [
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'core_message' => $item->core_message,
                'story_line' => $item->story_line,
            ],
            'branch' => [
                'id' => $branch->id,
                'channel' => $branch->channel->value,
                'format' => $branch->format->value,
            ],
            'article' => $content === null ? null : [
                'id' => $content->id,
                'headline' => $content->headline,
                'summary' => $content->summary,
                'body' => $content->body,
                'cover_suggestion' => $content->cover_suggestion,
                'image_suggestions' => $content->image_suggestions,
            ],
        ];
    }

    /**
     * 保存人工编辑结果。返回 warnings（图文文章当前无受保护类型，恒为空数组）。
     *
     * 语义：PUT body 中出现的字段按值 upsert 到 article 正式内容；
     * 真正发生变化的文章才补版本快照，未变化不产生版本。
     */
    public function save(ContentBranch $branch, array $data): array
    {
        $this->assertArticle($branch);
        $item = $branch->contentItem;

        DB::transaction(function () use ($item, $branch, $data): void {
            $this->applyItem($item, $data);

            $content = ArticleContent::where('content_branch_id', $branch->id)->first();
            if ($content === null) {
                $content = new ArticleContent([
                    'project_id' => $item->project_id,
                    'content_item_id' => $item->id,
                    'content_branch_id' => $branch->id,
                ]);
            }

            $changed = false;
            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $value = $data[$field];
                if (! $this->valuesEqual($content->$field, $value)) {
                    $changed = true;
                }
                $content->$field = $value;
            }

            if ($changed) {
                $content->save();
                $this->snapshot($content);
            }
        }, 3);

        return [];
    }

    private function applyItem(ContentItem $item, array $data): void
    {
        $update = [];
        foreach (self::ITEM_FIELDS as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $update[$field] = $data[$field];
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
            return json_encode(self::normalize((array) $before))
                === json_encode(self::normalize((array) $after));
        }

        return (string) ($before ?? '') === (string) ($after ?? '');
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $normalized = array_map('self::normalize', $value);
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function assertArticle(ContentBranch $branch): void
    {
        if ($branch->format !== ContentFormat::Article) {
            throw new InvalidArgumentException('仅 article 分支支持图文文章编辑。');
        }
    }
}
