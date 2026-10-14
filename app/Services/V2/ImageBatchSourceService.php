<?php

namespace App\Services\V2;

use App\Models\ContentPage;
use App\Models\ImageBatchUnitSetting;

/**
 * 图片批次 —— 来源变化检测。
 *
 * fingerprint 只反映「本页面当前内容」，纯内部判断用：
 *   - 对外只给 source_changed (bool) 与 source_change_summary (可读文案)；
 *   - 不暴露 hash / revision / 技术版本号。
 *
 * 尚未记录过 fingerprint 的页面，一律视为未变化（避免新建即报警）。
 */
class ImageBatchSourceService
{
    /** 参与指纹的页面内容字段（页面原文仍以 ContentPage 为准，不做复制存储）。 */
    private const TRACKED_FIELDS = [
        'page_no', 'page_type', 'narrative_role', 'new_fact',
        'final_copy', 'visual_evidence', 'continuity', 'production_note',
    ];

    public function fingerprint(ContentPage $page): string
    {
        $parts = [];

        foreach (self::TRACKED_FIELDS as $field) {
            $value = $page->getAttribute($field);
            $parts[] = $value instanceof \BackedEnum ? $value->value : (string) ($value ?? '');
        }

        return hash('sha256', implode("\u{241F}", $parts));
    }

    public function sourceChanged(ImageBatchUnitSetting $setting, ContentPage $page): bool
    {
        $stored = $setting->source_fingerprint;

        return $stored !== null && $stored !== '' && $stored !== $this->fingerprint($page);
    }

    /** 轻量可读摘要（不暴露技术编号）。 */
    public function changeSummary(ImageBatchUnitSetting $setting, ContentPage $page): ?string
    {
        return $this->sourceChanged($setting, $page)
            ? '来源页面内容有更新，建议检查本页生图是否受影响。'
            : null;
    }
}
