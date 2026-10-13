// V2-P2-C02 图文文章 DEV Mock 测试（node:test，DEV ONLY）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/article/articleDevMock.test.ts
// 说明：buildMockImportPreview 已从正式生产核心（articleCore.ts）隔离到
// articleDevMock.ts（DEV ONLY）。正式生产 composable 不引用本模块。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildMockImportPreview } from '../../../../resources/js/composables/v2/article/articleDevMock.ts';
import { normalizeArticleFields } from '../../../../resources/js/composables/v2/article/articleCore.ts';

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
