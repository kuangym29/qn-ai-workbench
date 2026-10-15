<?php

namespace Tests\Unit\V2;

use App\Services\V2\ArticleImportParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * RED: ArticleImportParser 尚未实现，这些测试必须先失败。
 *
 * 解析约定（与 image-post 同族但去掉逐页概念）：
 *   - 头部 key:value 解析 文章级 + 文章级共享字段（标题/核心表达/故事线）。
 *   - 以「正文 / body」为标记的行起，到下一个标记/EOF 的多行文本作为 body。
 *   - 以「配图建议 / 图片建议」为标记的行起，逐行解析为 image_suggestions 条目。
 */
class ArticleImportParserTest extends TestCase
{
    private function parser(): ArticleImportParser
    {
        return new ArticleImportParser();
    }

    public function test_parses_article_meta_fields(): void
    {
        $raw = "标题: 青柠夏日新品发布\n摘要: 一篇介绍新品的图文\n封面建议: 用柠檬黄主视觉\n核心表达: 清爽不腻\n故事线: 从果园到餐桌";
        $parsed = $this->parser()->parse($raw);

        $this->assertSame('青柠夏日新品发布', $parsed['article']['headline']);
        $this->assertSame('一篇介绍新品的图文', $parsed['article']['summary']);
        $this->assertSame('用柠檬黄主视觉', $parsed['article']['cover_suggestion']);
        $this->assertSame('清爽不腻', $parsed['item']['core_message']);
        $this->assertSame('从果园到餐桌', $parsed['item']['story_line']);
    }

    public function test_parses_multiline_body_after_marker(): void
    {
        $raw = "标题: 标题A\n正文:\n第一段内容。\n第二段内容。\n配图建议:\nposition: 3, description: 产品特写";
        $parsed = $this->parser()->parse($raw);

        $this->assertStringContainsString('第一段内容。', $parsed['article']['body']);
        $this->assertStringContainsString('第二段内容。', $parsed['article']['body']);
    }

    public function test_parses_image_suggestions_as_list(): void
    {
        $raw = "标题: 标题A\n配图建议:\nposition: 3, description: 产品特写, note: 需高清\nposition: , description: 场景图";
        $parsed = $this->parser()->parse($raw);

        $this->assertCount(2, $parsed['article']['image_suggestions']);
        $this->assertSame(3, $parsed['article']['image_suggestions'][0]['position']);
        $this->assertSame('产品特写', $parsed['article']['image_suggestions'][0]['description']);
        $this->assertSame('需高清', $parsed['article']['image_suggestions'][0]['note']);
        $this->assertNull($parsed['article']['image_suggestions'][1]['position']);
    }

    public function test_empty_raw_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse('');
    }

    public function test_nothing_parsed_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse("这是一段完全无法识别的闲聊文本，没有结构化键。");
    }

    public function test_invalid_image_suggestion_json_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $raw = "标题: 标题A\n配图建议:\n{bad json here";
        $this->parser()->parse($raw);
    }
}
