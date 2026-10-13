<?php

namespace App\Services\V2;

use App\Enums\PageType;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\VideoContent;
use Illuminate\Support\Collection;

/**
 * 视频制作的「来源」侧计算：来源页面 → 视频单元，以及来源变化检测。
 *
 * 设计要点：
 *   - 视频单元来源于当前 Content Item 的页面结构（ContentPage），用户不需要重复建立来源页。
 *   - 序号(order)、来源页(source_page_id)、来源标签(source_label) 都是**系统信息**，
 *     在 GET 时从 ContentPage 派生，不落库、不接受客户端写入。
 *   - 用户可编辑的只有 dynamic_instruction / duration_seconds / note。
 *   - source_fingerprint 只反映「视频当前依赖的来源页面版本」，对外不暴露 hash / 版本号。
 */
class VideoSourceService
{
    /** 未设置视频时长时的合理默认值（秒）。 */
    public const DEFAULT_DURATION_SECONDS = 3.0;

    public const MAX_DURATION_SECONDS = 600;

    /**
     * 当前来源 fingerprint：来源页面 id + 各自最新版本号的聚合摘要。
     * 只用于内部比对，不对外暴露。
     */
    public function currentFingerprint(ContentItem $item): string
    {
        $parts = [];
        foreach ($this->sourcePages($item) as $page) {
            $parts[] = $page->id.':'.((int) ($page->versions()->max('version_no') ?? 0));
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * 来源页面（按 page_no 升序）——视频单元的系统来源。
     *
     * @return Collection<int, ContentPage>
     */
    public function sourcePages(ContentItem $item)
    {
        return $item->contentPages()->orderBy('page_no')->get();
    }

    /**
     * 合并来源页面与已保存 units → 正式视频单元列表。
     *
     * 没有视频制作设置时返回空 dynamic_instruction / 默认 duration / null note。
     * 已保存 units 里出现但来源页已不存在的条目会被忽略（来源是系统事实）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function units(ContentItem $item, ?VideoContent $video): array
    {
        $saved = [];
        foreach ($video?->units ?? [] as $unit) {
            if (isset($unit['source_page_id'])) {
                $saved[(int) $unit['source_page_id']] = $unit;
            }
        }

        $units = [];
        foreach ($this->sourcePages($item) as $index => $page) {
            $unit = $saved[$page->id] ?? null;

            $units[] = [
                // ---- 系统信息（不可写） ----
                'order' => $index + 1,
                'source_page_id' => $page->id,
                'source_label' => $this->sourceLabel($page),
                // ---- 用户可编辑制作信息 ----
                'dynamic_instruction' => (string) ($unit['dynamic_instruction'] ?? ''),
                'duration_seconds' => $this->normalizeDuration($unit['duration_seconds'] ?? null),
                'note' => $unit['note'] ?? null,
            ];
        }

        return $units;
    }

    /**
     * 来源标签：只做轻量可读定位，不暴露 revision / hash / 技术版本号。
     */
    public function sourceLabel(ContentPage $page): string
    {
        $type = match ($page->page_type) {
            PageType::Cover => '封面',
            PageType::Content => '正文',
            PageType::ColumnClosing => '栏尾',
            PageType::FixedBackCover => '固定封底',
        };

        return sprintf('P%02d %s', $page->page_no, $type);
    }

    /**
     * video 是否尚未正式保存；未保存时 source_changed 恒为 false。
     */
    public function sourceChanged(?VideoContent $video, ContentItem $item): bool
    {
        if ($video === null || $video->source_fingerprint === null) {
            return false;
        }

        return ! hash_equals($video->source_fingerprint, $this->currentFingerprint($item));
    }

    /**
     * 轻量来源变化说明。不含 Revision ID / Hash / 技术版本号。
     *
     * @return array<string, mixed>
     */
    public function changeSummary(?VideoContent $video, ContentItem $item): array
    {
        if (! $this->sourceChanged($video, $item)) {
            return [];
        }

        $pageCount = $this->sourcePages($item)->count();

        return [
            'changed' => true,
            'message' => '来源页面内容已更新，视频制作可能需要复核。',
            'source_page_count' => $pageCount,
        ];
    }

    /**
     * duration 归一：缺失/非法 → 合理默认值；负数 → 归零由上层校验先拦截。
     */
    public function normalizeDuration(mixed $value): float
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_DURATION_SECONDS;
        }
        if (! is_numeric($value)) {
            return self::DEFAULT_DURATION_SECONDS;
        }

        $seconds = round((float) $value, 2);

        return $seconds < 0 ? 0.0 : min($seconds, (float) self::MAX_DURATION_SECONDS);
    }
}
