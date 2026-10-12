// V2-P2-C01 核心纯函数测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/imagePostCore.test.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  parsePageType,
  parseMarkdownPages,
  parseJsonPages,
  parseAiPaste,
  buildImportPreview,
  previewHasChanges,
} from '../../../resources/js/composables/v2/imagePostCore.ts';

test('parsePageType 识别中英文页面类型', () => {
  assert.equal(parsePageType('cover'), 'cover');
  assert.equal(parsePageType('封面'), 'cover');
  assert.equal(parsePageType('content'), 'content');
  assert.equal(parsePageType('正文'), 'content');
  assert.equal(parsePageType('column_closing'), 'column_closing');
  assert.equal(parsePageType('栏目收尾'), 'column_closing');
  assert.equal(parsePageType('fixed_back_cover'), 'fixed_back_cover');
  assert.equal(parsePageType('固定封底'), 'fixed_back_cover');
  assert.equal(parsePageType('未知类型'), null);
  assert.equal(parsePageType(null), null);
});

test('parseMarkdownPages 解析页码与字段行', () => {
  const raw = [
    '第1页',
    '页面类型：封面',
    '叙事职责：开篇引入',
    '最终文字：出门前，孩子总有自己的节奏。',
    '',
    '第2页',
    '页面类型：正文',
    '新事实：妈妈开始催促。',
    '画面证据：妈妈弯腰看表。',
    '前后页承接：引出孩子回应。',
    '制作备注：暖色调。',
  ].join('\n');
  const { pages, warnings } = parseMarkdownPages(raw);
  assert.equal(pages.length, 2);
  assert.equal(warnings.length, 0);
  assert.equal(pages[0].page_no, 1);
  assert.equal(pages[0].page_type, 'cover');
  assert.equal(pages[0].narrative_role, '开篇引入');
  assert.equal(pages[0].final_copy, '出门前，孩子总有自己的节奏。');
  assert.equal(pages[1].page_no, 2);
  assert.equal(pages[1].page_type, 'content');
  assert.equal(pages[1].new_fact, '妈妈开始催促。');
  assert.equal(pages[1].visual_evidence, '妈妈弯腰看表。');
  assert.equal(pages[1].continuity, '引出孩子回应。');
  assert.equal(pages[1].production_note, '暖色调。');
});

test('parseMarkdownPages 无页码时整段一页并警告', () => {
  const raw = '这是一段没有页码标记的内容。\n最终文字：只有一段。';
  const { pages, warnings } = parseMarkdownPages(raw);
  assert.equal(pages.length, 1);
  assert.ok(warnings.length >= 1);
});

test('parseMarkdownPages 无法识别行不静默丢弃', () => {
  const raw = ['第1页', '最终文字：正文内容。', '这行无法识别。'].join('\n');
  const { pages, warnings } = parseMarkdownPages(raw);
  assert.equal(pages.length, 1);
  assert.ok(warnings.some((w) => w.includes('未识别行')));
});

test('parseJsonPages 解析合法 JSON 并忽略非法条目', () => {
  const raw = JSON.stringify([
    { page_no: 1, page_type: 'cover', final_copy: 'A' },
    { page_no: 2, page_type: 'content', final_copy: 'B', pageNo: 3 },
    { page_no: 'x', final_copy: 'C' },
  ]);
  const { pages, warnings } = parseJsonPages(raw);
  assert.equal(pages.length, 2);
  assert.ok(warnings.length >= 1);
});

test('parseJsonPages 非数组 JSON 报错', () => {
  const { pages, warnings } = parseJsonPages('{"a":1}');
  assert.equal(pages.length, 0);
  assert.ok(warnings.some((w) => w.includes('顶层')));
});

test('parseAiPaste JSON 优先，非法 JSON 回退文本', () => {
  const jsonResult = parseAiPaste('[{"page_no":1,"final_copy":"JSON 内容"}]');
  assert.equal(jsonResult.pages.length, 1);
  assert.equal(jsonResult.pages[0].final_copy, 'JSON 内容');

  const textResult = parseAiPaste('第1页\n最终文字：文本内容');
  assert.equal(textResult.pages.length, 1);
  assert.equal(textResult.pages[0].final_copy, '文本内容');

  const empty = parseAiPaste('   ');
  assert.equal(empty.pages.length, 0);
  assert.ok(empty.warnings.some((w) => w.includes('空')));
});

test('buildImportPreview 区分 新增/修改/未变化', () => {
  const current = [
    {
      page_no: 1,
      page_type: 'cover' as const,
      narrative_role: '开篇',
      new_fact: null,
      final_copy: '原标题',
      visual_evidence: null,
      continuity: null,
      production_note: null,
    },
    {
      page_no: 2,
      page_type: 'content' as const,
      narrative_role: null,
      new_fact: null,
      final_copy: '未变页',
      visual_evidence: null,
      continuity: null,
      production_note: null,
    },
  ];
  const incoming = [
    { ...current[0], final_copy: '新标题' },
    {
      page_no: 3,
      page_type: 'column_closing' as const,
      narrative_role: null,
      new_fact: null,
      final_copy: '收尾',
      visual_evidence: null,
      continuity: null,
      production_note: null,
    },
  ];
  const preview = buildImportPreview(current, incoming);
  assert.equal(preview.added.length, 1);
  assert.equal(preview.added[0].page_no, 3);
  assert.equal(preview.modified.length, 1);
  assert.equal(preview.modified[0].page_no, 1);
  assert.equal(preview.modified[0].field, 'final_copy');
  assert.equal(preview.modified[0].before, '原标题');
  assert.equal(preview.modified[0].after, '新标题');
  // P2 未被本次导入触碰 → unchanged
  assert.deepEqual(preview.unchanged, [2]);
  assert.ok(previewHasChanges(preview));
});

test('buildImportPreview 局部更新只影响对应页', () => {
  const current = [1, 2, 3].map((n) => ({
    page_no: n,
    page_type: 'content' as const,
    narrative_role: null,
    new_fact: null,
    final_copy: `第${n}页`,
    visual_evidence: null,
    continuity: null,
    production_note: null,
  }));
  const incoming = [
    {
      page_no: 4,
      page_type: 'content' as const,
      narrative_role: null,
      new_fact: null,
      final_copy: '只贴第4页',
      visual_evidence: null,
      continuity: null,
      production_note: null,
    },
  ];
  const preview = buildImportPreview(current, incoming);
  assert.equal(preview.added.length, 1);
  assert.equal(preview.added[0].page_no, 4);
  assert.equal(preview.modified.length, 0);
  // 局部更新：现有 P1/P2/P3 未被触碰 → 明确显示为「未变化」
  assert.deepEqual(preview.unchanged, [1, 2, 3]);
});

test('buildImportPreview 页面类型变化只发警告不进入 modified', () => {
  const current = [
    {
      page_no: 1,
      page_type: 'content' as const,
      narrative_role: null,
      new_fact: null,
      final_copy: '同文',
      visual_evidence: null,
      continuity: null,
      production_note: null,
    },
  ];
  const incoming = [
    {
      page_no: 1,
      page_type: 'cover' as const,
      narrative_role: null,
      new_fact: null,
      final_copy: '同文',
      visual_evidence: null,
      continuity: null,
      production_note: null,
    },
  ];
  const preview = buildImportPreview(current, incoming);
  assert.equal(preview.modified.length, 0);
  assert.equal(preview.added.length, 0);
  // P1 被触碰（页面类型变化）→ 不算「未变化」
  assert.deepEqual(preview.unchanged, []);
  assert.ok(preview.warnings.some((w) => w.includes('页面类型')));
});
