// V2-P3-C05-R1 图片批次 DEV Mock 测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/imageBatch/imageBatchDevMock.test.ts
// 说明：本文件只测试 DEV 预览 Mock（imageBatchDevMock.ts）的展示行为；
// 正式生产模块（imageBatchCore / imageBatchApi / useImageBatch）不引用 Mock。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  applyMockCopyTask,
  applyMockResolveSourceChange,
  applyMockRun,
  applyMockSaveDraft,
  buildMockWorkspace,
  mockImageBatchStore,
} from '../../../../resources/js/composables/v2/imageBatch/imageBatchDevMock.ts';
import type { ImageBatchDraftInput } from '../../../../resources/js/types/v2/imageBatch/imageBatch.ts';

test('DEV Mock 工作台：10 个页面单元且字段齐全，不含 selected', async () => {
  const ws = await buildMockWorkspace();
  assert.equal(ws.units.length, 10);
  assert.ok(ws.content_title.length > 0);
  assert.ok(ws.source_label.length > 0);
  assert.ok(ws.batch_instruction.length > 0);
  for (const u of ws.units) {
    assert.ok(u.page_no >= 1 && u.page_no <= 10);
    assert.ok(['cover', 'content', 'column_closing', 'fixed_back_cover'].includes(u.page_type));
    assert.ok(Array.isArray(u.characters));
    // R1：勾选为前端本地状态，工作台单元不得携带 selected
    assert.ok(!('selected' in u), 'ImageBatchUnit 不应包含 selected 字段');
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

test('DEV Mock 复制任务：返回成功标记', async () => {
  const r = await applyMockCopyTask();
  assert.deepEqual(r, { task: { copied: true } });
});

test('DEV Mock 保存 Draft：更新工作台制作补充并记录 draft', async () => {
  const input: ImageBatchDraftInput = {
    batch_instruction: '新的整组说明',
    batch_note: '新的备注',
    units: [
      { id: 'u4', special_requirement: '新的特殊要求' },
      { id: 'u5', special_requirement: null },
    ],
  };
  const r = await applyMockSaveDraft(input);
  assert.deepEqual(r, { ok: true });
  assert.equal(mockImageBatchStore.workspace.batch_instruction, '新的整组说明');
  assert.equal(mockImageBatchStore.workspace.batch_note, '新的备注');
  assert.equal(mockImageBatchStore.workspace.units.find((u) => u.id === 'u4')?.special_requirement, '新的特殊要求');
  assert.equal(mockImageBatchStore.workspace.units.find((u) => u.id === 'u5')?.special_requirement, null);
  assert.deepEqual(mockImageBatchStore.draft, input);
});

test('DEV Mock 来源变化决策：keep 清掉提示；regenerate 标记需修改', async () => {
  const ws0 = await buildMockWorkspace();
  assert.equal(ws0.units.find((u) => u.id === 'u4')?.source_changed, true);

  await applyMockResolveSourceChange('u4', 'keep');
  const ws1 = await buildMockWorkspace();
  const u4 = ws1.units.find((u) => u.id === 'u4');
  assert.equal(u4?.source_changed, false);
  assert.equal(u4?.source_change_summary, null);

  await applyMockResolveSourceChange('u5', 'regenerate');
  const ws2 = await buildMockWorkspace();
  const u5 = ws2.units.find((u) => u.id === 'u5');
  assert.equal(u5?.source_changed, false);
  assert.equal(u5?.status, 'needs_revision');
});
