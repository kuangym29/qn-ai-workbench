<?php

namespace Tests\Unit\V2;

use App\Services\V2\ImagePostImportParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * RED: parser does not exist yet — these must fail (class not found) until GREEN.
 *
 * 解析契约（raw AI 文本 → 结构化）：
 *   - 文章级字段（核心表达 / 故事线 / 文章名称）放在第一个分隔符之前。
 *   - 页面块以单独一行的 --- 或 === 分隔。
 *   - 块内用「键: 值」描述 8 个正式字段。
 *   - page_type 接受英文枚举值与中文别名。
 */
class ImagePostImportParserTest extends TestCase
{
    private function parser(): ImagePostImportParser
    {
        return new ImagePostImportParser();
    }

    private function sampleRaw(): string
    {
        return <<<RAW
核心表达: 坚持做难而正确的事
故事线: 从迷茫到坚定的旅程

--- 第1页 ---
页码: 1
页面类型: cover
叙事职责: 开篇引入主题
本页新事实: 主角决定启程
最终文字: 你准备好了吗
画面证据: 晨光中的背影
前后页承接: 引出挑战
制作备注: 配色温暖

--- 第2页 ---
页码: 2
页面类型: content
叙事职责: 展开冲突
本页新事实: 遇到困难
最终文字: 但并未放弃
画面证据: 雨中前行
前后页承接: 迎来转机
制作备注: 
RAW;
    }

    public function test_parses_multi_page_with_all_eight_fields(): void
    {
        $result = $this->parser()->parse($this->sampleRaw());

        $this->assertCount(2, $result['pages']);
        $first = $result['pages'][0];
        $this->assertSame(1, $first['page_no']);
        $this->assertSame('cover', $first['page_type']);
        $this->assertSame('开篇引入主题', $first['narrative_role']);
        $this->assertSame('主角决定启程', $first['new_fact']);
        $this->assertSame('你准备好了吗', $first['final_copy']);
        $this->assertSame('晨光中的背影', $first['visual_evidence']);
        $this->assertSame('引出挑战', $first['continuity']);
        $this->assertSame('配色温暖', $first['production_note']);
        // 第2页的空制作备注归一为 null（可选字段）。
        $this->assertNull($result['pages'][1]['production_note']);
    }

    public function test_parses_item_level_core_message_story_line_and_title(): void
    {
        $result = $this->parser()->parse($this->sampleRaw());

        $this->assertSame('坚持做难而正确的事', $result['item']['core_message']);
        $this->assertSame('从迷茫到坚定的旅程', $result['item']['story_line']);
        $this->assertArrayNotHasKey('title', $result['item']);
    }

    public function test_accepts_chinese_page_type_aliases(): void
    {
        $raw = <<<RAW
--- 第1页 ---
页码: 1
页面类型: 封面
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e

--- 第2页 ---
页码: 2
页面类型: 栏目收尾
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e

--- 第3页 ---
页码: 3
页面类型: 固定封底
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e
RAW;

        $result = $this->parser()->parse($raw);

        $this->assertSame('cover', $result['pages'][0]['page_type']);
        $this->assertSame('column_closing', $result['pages'][1]['page_type']);
        $this->assertSame('fixed_back_cover', $result['pages'][2]['page_type']);
    }

    public function test_throws_when_page_missing_page_no(): void
    {
        $raw = <<<RAW
--- 第1页 ---
页面类型: content
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e
RAW;

        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse($raw);
    }

    public function test_throws_when_invalid_page_type(): void
    {
        $raw = <<<RAW
--- 第1页 ---
页码: 1
页面类型: poster
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e
RAW;

        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse($raw);
    }

    public function test_throws_on_duplicate_page_no(): void
    {
        $raw = <<<RAW
--- 第1页 ---
页码: 1
页面类型: content
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e

--- 第2页 ---
页码: 1
页面类型: cover
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e
RAW;

        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse($raw);
    }

    public function test_throws_on_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse('');
    }

    public function test_throws_when_page_no_not_positive(): void
    {
        $raw = <<<RAW
--- 第1页 ---
页码: 0
页面类型: content
叙事职责: a
本页新事实: b
最终文字: c
画面证据: d
前后页承接: e
RAW;

        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse($raw);
    }
    public function test_multiline_item_and_page_text_is_preserved(): void
    {
        $parsed = $this->parser()->parse("story_line: first\nsecond\n---\npage_no: 4\npage_type: content\nfinal_copy: line one\nline two\nDialogue: hello");
        $this->assertSame("first\nsecond", $parsed['item']['story_line']);
        $this->assertSame("line one\nline two\nDialogue: hello", $parsed['pages'][0]['final_copy']);
    }

    public function test_non_integer_page_numbers_are_rejected(): void
    {
        foreach (['1.5', '1e2', '+2'] as $number) {
            try {
                $this->parser()->parse("---\npage_no: {$number}\npage_type: content");
                $this->fail('Invalid page number accepted: '.$number);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('正整数', $exception->getMessage());
            }
        }
    }

    public function test_overlong_import_title_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse('title: '.str_repeat('a', 201)."\n---\npage_no: 1\npage_type: content");
    }
}
