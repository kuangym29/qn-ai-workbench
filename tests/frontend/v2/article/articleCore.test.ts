// V2-P2-C02 图文文章核心纯函数测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/article/articleCore.test.ts
// 只测试正式编辑器的展示与配图编辑辅助函数。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeArticleFields,
  addImageSuggestion,
  removeImageSuggestion,
  updateImageSuggestion,
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

test('normalizeArticleFields：非字符串标题 → null，配图 JSON 原样保留供后端校验', () => {
  const n = normalizeArticleFields({
    headline: 123 as unknown as string,
    image_suggestions: [
      { position: 2, description: '有效项' },
      { position: 0, description: '' },
      { position: 'x' as unknown as number, description: '  ' },
    ] as never,
  });
  assert.equal(n.headline, null);
  assert.equal(n.image_suggestions?.length, 3);
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

test('GET suggestions retain JSON keys and whitespace so unchanged save creates no version', () => {
  const images = [{ position: 2, description: '  原文保留  ' }];
  assert.deepEqual(normalizeArticleFields({ image_suggestions: images }).image_suggestions, images);
});
