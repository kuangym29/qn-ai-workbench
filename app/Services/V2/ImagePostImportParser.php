<?php

namespace App\Services\V2;

use InvalidArgumentException;

/**
 * 把 AI 返回的整段文本解析成结构化页面数组。
 *
 * 流程中的第一步：raw → parse（本类）→ normalize → validate。
 * 这里只负责"解析 + 基础校验"，不触碰数据库、不区分预览/应用。
 *
 * 约定格式：
 *   - 文章级字段（核心表达 / 故事线 / 文章名称）写在第一个分隔符之前。
 *   - 页面块以单独一行的 --- 或 ===（或以此开头）分隔。
 *   - 块内用「键: 值」描述字段；也支持 "# 核心表达" 头风格。
 *   - page_type 接受英文枚举值与中文别名。
 */
class ImagePostImportParser
{
    private const ITEM_KEYS = [
        '核心表达' => 'core_message',
        'core message' => 'core_message',
        'core_message' => 'core_message',
        '故事线' => 'story_line',
        'story line' => 'story_line',
        'story_line' => 'story_line',
        '文章名称' => 'title',
        '标题' => 'title',
        'title' => 'title',
    ];

    private const PAGE_KEYS = [
        '页码' => 'page_no',
        'pageno' => 'page_no',
        'page_no' => 'page_no',
        'page number' => 'page_no',
        '页面类型' => 'page_type',
        'page_type' => 'page_type',
        'type' => 'page_type',
        '叙事职责' => 'narrative_role',
        'narrative_role' => 'narrative_role',
        'narrative role' => 'narrative_role',
        '本页新事实' => 'new_fact',
        'new_fact' => 'new_fact',
        'new fact' => 'new_fact',
        '最终文字' => 'final_copy',
        'final_copy' => 'final_copy',
        'final copy' => 'final_copy',
        '画面证据' => 'visual_evidence',
        'visual_evidence' => 'visual_evidence',
        'visual evidence' => 'visual_evidence',
        '前后页承接' => 'continuity',
        'continuity' => 'continuity',
        '制作备注' => 'production_note',
        'production_note' => 'production_note',
        'production note' => 'production_note',
    ];

    private const PAGE_TYPE_ALIASES = [
        '封面' => 'cover',
        'cover' => 'cover',
        '内容' => 'content',
        '正文' => 'content',
        'content' => 'content',
        '栏目收尾' => 'column_closing',
        'column_closing' => 'column_closing',
        '固定封底' => 'fixed_back_cover',
        'fixed_back_cover' => 'fixed_back_cover',
    ];

    public function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException('AI 导入内容为空。');
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $item = [];
        $pages = [];
        $current = null;
        $inItem = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($this->isDelimiter($line)) {
                if ($current !== null) {
                    $pages[] = $current;
                }
                $current = [];
                $inItem = false;
                continue;
            }

            $kv = $this->parseKeyValue($line);
            if ($kv === null) {
                continue;
            }
            [$key, $value] = $kv;

            if ($inItem) {
                if (array_key_exists($key, self::ITEM_KEYS)) {
                    $item[self::ITEM_KEYS[$key]] = $value;
                }
            } else {
                if (array_key_exists($key, self::PAGE_KEYS)) {
                    $current[self::PAGE_KEYS[$key]] = $value;
                }
            }
        }
        if ($current !== null) {
            $pages[] = $current;
        }

        if (count($pages) === 0) {
            throw new InvalidArgumentException('未解析到任何页面。');
        }

        $normalized = array_map([$this, 'normalizePage'], $pages);

        // 重复页码检测（稳定页面身份：同一 branch 内 page_no 唯一）。
        $seen = [];
        foreach ($normalized as $page) {
            if (in_array($page['page_no'], $seen, true)) {
                throw new InvalidArgumentException("页码 {$page['page_no']} 重复出现。");
            }
            $seen[] = $page['page_no'];
        }

        return ['item' => $item, 'pages' => $normalized];
    }

    private function isDelimiter(string $line): bool
    {
        if (preg_match('/^(={3,}|-{3,})$/', $line)) {
            return true;
        }

        return str_starts_with($line, '---') || str_starts_with($line, '===');
    }

    private function parseKeyValue(string $line): ?array
    {
        $work = ltrim($line, "# \t");
        if (! preg_match('/^(.*?)[:：](.*)$/u', $work, $m)) {
            return null;
        }

        return [strtolower(trim($m[1])), trim($m[2])];
    }

    private function normalizePage(array $page): array
    {
        if (! isset($page['page_no'])) {
            throw new InvalidArgumentException('存在缺少「页码」的页面。');
        }
        if (! is_numeric($page['page_no']) || (int) $page['page_no'] < 1) {
            throw new InvalidArgumentException('页码必须为正整数。');
        }
        $pageNo = (int) $page['page_no'];

        if (! isset($page['page_type'])) {
            throw new InvalidArgumentException("第 {$pageNo} 页缺少「页面类型」。");
        }
        $typeKey = strtolower(trim($page['page_type']));
        if (! array_key_exists($typeKey, self::PAGE_TYPE_ALIASES)) {
            throw new InvalidArgumentException("第 {$pageNo} 页的页面类型「{$page['page_type']}」无效。");
        }

        return [
            'page_no' => $pageNo,
            'page_type' => self::PAGE_TYPE_ALIASES[$typeKey],
            'narrative_role' => $this->nullIfEmpty($page['narrative_role'] ?? null),
            'new_fact' => $this->nullIfEmpty($page['new_fact'] ?? null),
            'final_copy' => $this->nullIfEmpty($page['final_copy'] ?? null),
            'visual_evidence' => $this->nullIfEmpty($page['visual_evidence'] ?? null),
            'continuity' => $this->nullIfEmpty($page['continuity'] ?? null),
            'production_note' => $this->nullIfEmpty($page['production_note'] ?? null),
        ];
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }
}
