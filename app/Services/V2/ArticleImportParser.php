<?php

namespace App\Services\V2;

use InvalidArgumentException;

/**
 * 把 AI 返回的整段文本解析成图文文章（article）结构化数组。
 *
 * 流程第一步：raw → parse（本类）→ normalize → validate。
 * 只负责解析 + 基础校验，不碰数据库、不区分预览/应用。
 *
 * 约定格式（与 image-post 同族，但去掉逐页概念）：
 *   - 头部「键: 值」行解析文章级字段（headline/summary/cover_suggestion）
 *     与文章级共享字段（核心表达/故事线/文章名称）。
 *   - 以「正文 / body」为标记的行起，到下一个标记或 EOF 之间的多行文本作为 body。
 *   - 以「配图建议 / 图片建议」为标记的行起，逐行解析为 image_suggestions 条目
 *     （每行可为 JSON 对象，或 "position: 3, description: ..., note: ..." 风格）。
 */
class ArticleImportParser
{
    private const ITEM_KEYS = [
        '核心表达' => 'core_message', 'core message' => 'core_message', 'core_message' => 'core_message',
        '故事线' => 'story_line', 'story line' => 'story_line', 'story_line' => 'story_line',
        '文章名称' => 'title', '名称' => 'title', 'title' => 'title',
    ];

    private const ARTICLE_KEYS = [
        '标题' => 'headline', 'headline' => 'headline', '文章标题' => 'headline', '图文标题' => 'headline',
        '摘要' => 'summary', 'summary' => 'summary',
        '封面建议' => 'cover_suggestion', 'cover_suggestion' => 'cover_suggestion', 'cover' => 'cover_suggestion',
    ];

    private const IMAGE_KEYS = [
        'position' => 'position', '位置' => 'position',
        'description' => 'description', '描述' => 'description', '说明' => 'description', 'desc' => 'description',
        'note' => 'note', '备注' => 'note',
    ];

    private const BODY_MARKERS = ['正文', 'body', '文章内容', 'article body'];
    private const IMAGE_MARKERS = ['配图建议', '图片建议', '配图', '图片', 'image_suggestions', 'image suggestions'];

    public function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException('AI 导入内容为空。');
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $item = [];
        $article = [];
        $bodyBuf = [];
        $imageLines = [];
        $section = 'header';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                // 空行：正文段内保留为换行，其它段忽略。
                if ($section === 'body') {
                    $bodyBuf[] = '';
                }
                continue;
            }

            if ($this->isSectionMarker($line, self::BODY_MARKERS)) {
                $section = 'body';
                continue;
            }
            if ($this->isSectionMarker($line, self::IMAGE_MARKERS)) {
                $section = 'images';
                continue;
            }

            if ($section === 'header') {
                $kv = $this->parseKeyValue($line);
                if ($kv !== null) {
                    [$key, $value] = $kv;
                    $this->assignHeader($item, $article, $key, $value);
                }
            } elseif ($section === 'body') {
                $bodyBuf[] = $line;
            } else { // images
                $imageLines[] = $line;
            }
        }

        if ($bodyBuf !== []) {
            $article['body'] = trim(implode("\n", $bodyBuf));
        }
        if ($imageLines !== []) {
            $article['image_suggestions'] = $this->parseImageSuggestions($imageLines);
        }

        if ($item === [] && $article === []) {
            throw new InvalidArgumentException('未解析到任何可导入内容（文章或文章级字段为空）。');
        }

        return ['item' => $item, 'article' => $article];
    }

    private function isSectionMarker(string $line, array $markers): bool
    {
        $lower = rtrim(strtolower(trim($line)), ':：');

        return in_array($lower, $markers, true);
    }

    private function parseKeyValue(string $line): ?array
    {
        $work = ltrim($line, "# \t");
        if (! preg_match('/^(.*?)[:：](.*)$/u', $work, $m)) {
            return null;
        }

        return [strtolower(trim($m[1])), trim($m[2])];
    }

    private function assignHeader(array &$item, array &$article, string $key, string $value): void
    {
        if (array_key_exists($key, self::ARTICLE_KEYS)) {
            $article[self::ARTICLE_KEYS[$key]] = $value;
        } elseif (array_key_exists($key, self::ITEM_KEYS)) {
            $item[self::ITEM_KEYS[$key]] = $value;
        }
    }

    /**
     * @param string[] $lines
     * @return array<int, array{position?:int|null, description?:string|null, note?:string|null}>
     */
    private function parseImageSuggestions(array $lines): array
    {
        $suggestions = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (! is_array($decoded)) {
                    throw new InvalidArgumentException("配图建议包含无法解析的 JSON：{$trimmed}");
                }
                $suggestions[] = $this->normalizeImageEntry($decoded);
                continue;
            }
            $suggestions[] = $this->normalizeImageEntry($this->parseImageLine($trimmed));
        }

        return $suggestions;
    }

    /**
     * @return array{position?:int|null, description?:string|null, note?:string|null}
     */
    private function parseImageLine(string $line): array
    {
        $entry = [];
        foreach (explode(',', $line) as $token) {
            $kv = $this->parseKeyValue($token);
            if ($kv === null) {
                continue;
            }
            [$key, $value] = $kv;
            if (! array_key_exists($key, self::IMAGE_KEYS)) {
                continue;
            }
            $field = self::IMAGE_KEYS[$key];
            $entry[$field] = ($field === 'position') ? ($value === '' ? null : (int) $value) : $value;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{position?:int|null, description?:string|null, note?:string|null}
     */
    private function normalizeImageEntry(array $entry): array
    {
        $out = [];
        if (array_key_exists('position', $entry)) {
            $pos = $entry['position'];
            $out['position'] = ($pos === null || $pos === '') ? null : (int) $pos;
        }
        if (array_key_exists('description', $entry)) {
            $out['description'] = $entry['description'] === '' ? null : (string) $entry['description'];
        }
        if (array_key_exists('note', $entry)) {
            $out['note'] = $entry['note'] === '' ? null : (string) $entry['note'];
        }

        return $out;
    }
}
