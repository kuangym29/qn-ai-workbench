// V2-P3-C04 审核中心 DEV Mock 测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/review/reviewDevMock.test.ts
// 说明：本文件只测试 DEV 预览 Mock（reviewDevMock.ts）的展示行为；
// 正式生产模块（reviewCore / reviewApi / useReviewCenter）不引用 Mock。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  applyMockDecision,
  buildMockReviewDetail,
  buildMockReviewList,
} from '../../../../resources/js/composables/v2/review/reviewDevMock.ts';

test('DEV Mock 列表：≥5 条且全部 pending', async () => {
  const items = await buildMockReviewList();
  assert.ok(items.length >= 5, `预期至少 5 条待审核，实际 ${items.length}`);
  for (const item of items) {
    assert.equal(item.status, 'pending');
    assert.ok(item.content_title.length > 0);
    assert.ok(item.review_target_label.length > 0);
    assert.ok(item.version_label.length > 0);
  }
});

test('DEV Mock 列表：覆盖小绿书 / 图文 / 视频审核对象', async () => {
  const items = await buildMockReviewList();
  const labels = new Set(items.map((i) => i.review_target_label));
  assert.ok(labels.has('小绿书内容'));
  assert.ok(labels.has('公众号图文'));
  assert.ok(labels.has('视频制作'));
});

test('DEV Mock 详情：可查询且含预览', async () => {
  const items = await buildMockReviewList();
  const d = await buildMockReviewDetail(items[0].id);
  assert.ok(d !== null);
  assert.equal(d.id, items[0].id);
  assert.ok(d.preview !== null, '详情应包含预览区域');
  assert.ok(
    d.preview.kind === 'text' || d.preview.kind === 'lines' || d.preview.kind === 'items',
  );
});

test('DEV Mock 详情：不存在的 id 返回 null', async () => {
  const d = await buildMockReviewDetail(999999);
  assert.equal(d, null);
});

test('DEV Mock 决策：approved / changes_requested 后退出 pending 列表', async () => {
  const before = await buildMockReviewList();
  const target = before[0];

  const r1 = await applyMockDecision(target.id, { decision: 'approved', comment: null });
  assert.equal(r1.review_id, target.id);
  assert.equal(r1.decision, 'approved');
  assert.equal(r1.comment, null);
  assert.equal(r1.target_label, null);

  const after = await buildMockReviewList();
  assert.ok(!after.some((i) => i.id === target.id), '已决策项不应再出现在 pending 列表');

  const d = await buildMockReviewDetail(target.id);
  assert.equal(d?.status, 'approved');
});

test('DEV Mock 决策：退回保留批注与轻量 target_label', async () => {
  const before = await buildMockReviewList();
  const target = before[1];

  const r = await applyMockDecision(target.id, {
    decision: 'changes_requested',
    comment: 'P4 的画面证据与文案不一致，请补充细节。',
    target_label: 'P4',
  });
  assert.equal(r.decision, 'changes_requested');
  assert.ok(r.comment?.includes('P4'));
  assert.equal(r.target_label, 'P4');

  const after = await buildMockReviewList();
  assert.ok(!after.some((i) => i.id === target.id));
});

test('DEV Mock 决策：不存在的 id 抛错（模拟 404）', async () => {
  await assert.rejects(
    () => applyMockDecision(999999, { decision: 'approved', comment: null }),
    /不存在/,
  );
});
