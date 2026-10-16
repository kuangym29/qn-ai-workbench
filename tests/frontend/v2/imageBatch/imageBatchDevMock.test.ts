// V2-P3-C05 图片批次 DEV Mock 测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/imageBatch/imageBatchDevMock.test.ts
// 说明：本文件只测试 DEV 预览 Mock（imageBatchDevMock.ts）的展示行为；
// 正式生产模块（imageBatchCore / imageBatchApi / useImageBatch）不引用 Mock。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  applyMockCopyTask,
  applyMockRun,
  buildMockWorkspace,
  mockImageBatchStore,
  saveMockDraft,
  resolveMockSourceChange,
} from '../../../../resources/js/composables/v2/imageBatch/imageBatchDevMock.ts';

test('DEV Mock 工作台：10 个页面单元且字段齐全', async () => {
  const ws = await buildMockWorkspace();
  assert.equal(ws.units.length, 10);
  assert.ok(ws.content_title.length > 0);
  assert.ok(ws.source_label.length > 0);
  assert.ok(ws.batch_instruction.length > 0);
  for (const u of ws.units) {
    assert.ok(u.page_no >= 1 && u.page_no <= 10);
    assert.ok(['cover', 'content', 'column_closing', 'fixed_back_cover'].includes(u.page_type));
    assert.ok(Array.isArray(u.characters));
    assert.equal('selected' in u, false);
    assert.ok(['pending', 'generated', 'needs_revision', 'approved'].includes(u.status));
  }
});

test('DEV Mock 工作台：资产摘要覆盖 已有 / 缺失 / 本批新建，且人物与场景齐全', async () => {
  const ws = await buildMockWorkspace();
  const statuses = new Set(ws.assets.map((a) => a.status));
  assert.ok(statuses.has('existing'));
  assert.ok(statuses.has('missing'));
  assert.ok(statuses.has('new_in_batch'));
  assert.ok(ws.assets.some((a) => a.type === 'character'));
  assert.ok(ws.assets.some((a) => a.type === 'scene'));
});

test('DEV Mock 工作台：来源变化提示页存在（P4 / P5）', async () => {
  const ws = await buildMockWorkspace();
  const changed = ws.units.filter((u) => u.source_changed === true);
  assert.equal(changed.length, 2);
  assert.deepEqual(
    changed.map((u) => u.page_no).sort((a, b) => a - b),
    [4, 5],
  );
  for (const u of changed) {
    assert.ok(u.source_change_summary && u.source_change_summary.length > 0);
  }
});

test('DEV Mock 历史：初始 2 条，字段齐全', async () => {
  const ws = await buildMockWorkspace();
  assert.equal(ws.history.length, 2);
  for (const h of ws.history) {
    assert.ok(Array.isArray(h.page_labels) && h.page_labels.length > 0);
    assert.ok(h.created_at.length > 0);
    assert.ok(h.status.length > 0);
  }
});

test('DEV Mock 执行：整批生成覆盖全部页面并追加历史', async () => {
  const before = mockImageBatchStore.history.length;
  const r = await applyMockRun({ mode: 'full', unit_ids: [] });
  assert.equal(r.mode, 'full');
  assert.ok(r.batch_id !== null);
  assert.equal(r.unit_ids.length, 10);
  assert.equal(r.status, 'pending');
  assert.equal(mockImageBatchStore.history.length, before + 1);
  assert.equal(mockImageBatchStore.history[0].page_labels.length, 10);
});

test('DEV Mock 执行：试样只包含勾选页面（如 P4 / P5）', async () => {
  const before = mockImageBatchStore.history.length;
  const r = await applyMockRun({ mode: 'trial', unit_ids: ['u4', 'u5'] });
  assert.equal(r.mode, 'trial');
  assert.deepEqual(
    mockImageBatchStore.history[0].page_labels,
    ['P4', 'P5'],
  );
  assert.equal(mockImageBatchStore.history.length, before + 1);
});

test('DEV Mock 复制任务：返回服务端任务文本', async () => {
  const r = await applyMockCopyTask();
  assert.equal(typeof r.task, 'string'); assert.ok(r.task.includes('DEV 服务端任务'));
});

test('DEV Mock Draft 保存后 GET 返回最新草稿且不写系统字段', async () => {
  const before = await buildMockWorkspace();
  await saveMockDraft({ batch_instruction: 'saved instruction', batch_note: 'saved note', units: [{ id: 'u1', special_requirement: 'saved special' }] });
  const after = await buildMockWorkspace();
  assert.equal(after.batch_instruction, 'saved instruction');
  assert.equal(after.batch_note, 'saved note');
  assert.equal(after.units[0].special_requirement, 'saved special');
  assert.equal(after.units[0].final_copy, before.units[0].final_copy);
  assert.equal(after.units[0].status, before.units[0].status);
});

test('DEV Mock 来源决策仅经模拟服务端改变，GET 返回结果', async () => {
  const before = await buildMockWorkspace();
  await resolveMockSourceChange('u4', { action: 'keep' });
  await resolveMockSourceChange('u5', { action: 'regenerate' });
  const after = await buildMockWorkspace();
  assert.equal(before.units[3].source_changed, true);
  assert.equal(after.units[3].source_changed, false);
  assert.equal(after.units[3].status, before.units[3].status);
  assert.equal(after.units[4].source_changed, false);
  assert.equal(after.units[4].status, 'needs_revision');
});
