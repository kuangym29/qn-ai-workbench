// V2-P2-C02 图文文章核心纯函数测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/article/articleCore.test.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeArticleFields,
  addImageSuggestion,
  removeImageSuggestion,
  updateImageSuggestion,
  buildMockImportPreview,
  ARTICLE_FIELD_LABELS,
} from '../../../../resources/js/composables/v2/article/articleCore.ts';
import type { ArticleFields } from '../../../../resources/js/types/v2/article/article.ts';

test('normalizeArticleFields：空串/空白 → null，长正文原样保留', () => {
  const n = normalizeArticleFields({
    headline: '',
    summary: '   ',
    body: '第一段。\n\n第二段很长很长。'.repeat(50),
    image_suggestions: [],
    cover_suggestion: null,
  });
  assert.equal(n.headline, null);
  assert.equal(n.summary, null);
  assert.ok(n.body !== null && n.body.includes('第二段很长很长。'));
  assert.equal(n.image_suggestions, null);
  assert.equal(n.cover_suggestion, null);
});

test('normalizeArticleFields：非字符串输入 → null，配图列表过滤非法项', () => {
  const n = normalizeArticleFields({
    headline: 123 as unknown as string,
    image_suggestions: [
      { position: 2, description: '有效项' },
      { position: 0, description: '' },
      { position: 'x' as unknown as number, description: '  ' },
    ] as never,
  });
  assert.equal(n.headline, null);
  assert.equal(n.image_suggestions?.length, 1);
  assert.equal(n.image_suggestions?.[0].description, '有效项');
});

test('addImageSuggestion / removeImageSuggestion / updateImageSuggestion', () => {
  let list: ArticleFields['image_suggestions'] = null;
  list = addImageSuggestion(list, { position: null, description: 'A' });
  list = addImageSuggestion(list, { position: 3, description: 'B' });
  assert.equal(list?.length, 2);
  list = updateImageSuggestion(list, 0, { position: 1, note: 'n1' });
  assert.equal(list?.[0].position, 1);
  assert.equal(list?.[0].note, 'n1');
  list = removeImageSuggestion(list, 1);
  assert.equal(list?.length, 1);
  assert.equal(list?.[0].description, 'A');
  // 删空 → null（无配图）
  list = removeImageSuggestion(list, 0);
  assert.equal(list, null);
  // 越界保护
  assert.equal(removeImageSuggestion(list, 5), null);
});

test('buildMockImportPreview：字段行 → modified（DEV MOCK）', () => {
  const current = normalizeArticleFields(null);
  const p = buildMockImportPreview('标题：新的标题\n摘要：新的摘要', current);
  assert.equal(p.modified.length, 2);
  assert.equal(p.modified[0].field, 'headline');
  assert.equal(p.modified[0].after, '新的标题');
  assert.equal(p.modified[1].field, 'summary');
  // 其余字段未变化
  assert.ok(p.unchanged.includes('正文'));
  assert.ok(p.unchanged.includes('封面建议'));
  assert.ok(p.warnings.some((w) => w.includes('DEV')));
});

test('buildMockImportPreview：配图行 → added（DEV MOCK）', () => {
  const current = normalizeArticleFields(null);
  const p = buildMockImportPreview('配图：妈妈蹲下微笑，孩子抬头。', current);
  assert.equal(p.added.length, 1);
  assert.equal(p.added[0].label, '新增配图建议');
  assert.equal(p.added[0].detail, '妈妈蹲下微笑，孩子抬头。');
});

test('buildMockImportPreview：空输入 → 仅 warnings', () => {
  const p = buildMockImportPreview('   ', normalizeArticleFields(null));
  assert.equal(p.added.length, 0);
  assert.equal(p.modified.length, 0);
  assert.equal(p.unchanged.length, 0);
  assert.ok(p.warnings.some((w) => w.includes('空')));
});

test('buildMockImportPreview：无法识别行进入 warnings（DEV MOCK）', () => {
  const p = buildMockImportPreview('这行没有字段标记', normalizeArticleFields(null));
  assert.ok(p.warnings.some((w) => w.includes('未识别行')));
});

test('ARTICLE_FIELD_LABELS 覆盖全部字段键', () => {
  const keys: (keyof typeof ARTICLE_FIELD_LABELS)[] = [
    'headline',
    'summary',
    'body',
    'image_suggestions',
    'cover_suggestion',
  ];
  for (const k of keys) {
    assert.ok(ARTICLE_FIELD_LABELS[k].length > 0);
  }
});
