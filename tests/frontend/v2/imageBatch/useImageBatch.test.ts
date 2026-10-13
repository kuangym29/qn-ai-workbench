// V2-P3-C05-R1 图片批次状态机测试（node:test + vue reactivity，无需浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/imageBatch/useImageBatch.test.ts
// 覆盖 R1 合同重点：
//   - 勾选为前端本地状态（selectedUnitIds），不属于服务端字段，reload 后清空；
//   - 制作补充修改 → dirty；run / copy-task 前 dirty 自动先 PUT Draft；
//   - PUT 成功才 run / copy-task；PUT 失败不执行；
//   - full 不依赖勾选；trial / partial 依赖本地勾选；
//   - keep / regenerate 调用正式 source-change API，成功后重新 GET。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { useImageBatch } from '../../../../resources/js/composables/v2/imageBatch/useImageBatch.ts';
import type {
  ImageBatchDraftInput,
  ImageBatchRunInput,
  ImageBatchUnit,
  ImageBatchWorkspace,
  SourceChangeInput,
} from '../../../../resources/js/types/v2/imageBatch/imageBatch.ts';

function makeUnit(overrides: Partial<ImageBatchUnit> = {}): ImageBatchUnit {
  return {
    id: 'u1',
    page_id: 1,
    page_no: 1,
    page_type: 'content',
    final_copy: '第一行文字',
    visual_evidence: '画面描述',
    characters: ['妈妈'],
    scene: '客厅',
    special_requirement: null,
    status: 'pending',
    ...overrides,
  };
}

function buildFixtureWorkspace(): ImageBatchWorkspace {
  return {
    content_title: '测试篇目',
    source_label: '生活小能力 › 来源',
    batch_instruction: '整组说明',
    batch_note: '本批备注',
    assets: [
      { type: 'character', name: '妈妈', status: 'existing' },
      { type: 'scene', name: '客厅', status: 'existing' },
    ],
    units: [
      makeUnit({ id: 'u1', page_no: 1, page_type: 'cover', special_requirement: null }),
      makeUnit({ id: 'u2', page_no: 2, special_requirement: '表情特写' }),
      makeUnit({ id: 'u3', page_no: 3, special_requirement: null }),
      makeUnit({ id: 'u4', page_no: 4, source_changed: true, source_change_summary: '最终文字有更新。' }),
    ],
    history: [],
  };
}

interface FakeCalls {
  order: string[];
  load: number;
  saveDraft: number;
  run: number;
  copyTask: number;
  sourceChange: { unitId: number | string; action: 'keep' | 'regenerate' }[];
  runInputs: ImageBatchRunInput[];
  draftInputs: ImageBatchDraftInput[];
}

function createFake() {
  let workspace: ImageBatchWorkspace = buildFixtureWorkspace();
  let failSave = false;
  const calls: FakeCalls = { order: [], load: 0, saveDraft: 0, run: 0, copyTask: 0, sourceChange: [], runInputs: [], draftInputs: [] };
  const client = {
    async loadWorkspace() {
      calls.order.push('load');
      calls.load++;
      return structuredClone(workspace);
    },
    async saveDraft(_p: number | string, _i: number | string, _b: number | string, input: ImageBatchDraftInput) {
      calls.order.push('saveDraft');
      calls.saveDraft++;
      calls.draftInputs.push(structuredClone(input));
      if (failSave) throw new Error('模拟保存失败');
      workspace = {
        ...workspace,
        batch_instruction: input.batch_instruction,
        batch_note: input.batch_note,
        units: workspace.units.map((u) => {
          const d = input.units.find((x) => String(x.id) === String(u.id));
          return d ? { ...u, special_requirement: d.special_requirement } : u;
        }),
      };
      return { ok: true };
    },
    async run(_p: number | string, _i: number | string, _b: number | string, input: ImageBatchRunInput) {
      calls.order.push('run');
      calls.run++;
      calls.runInputs.push(structuredClone(input));
      return { batch_id: 'b1', mode: input.mode, unit_ids: input.unit_ids, status: 'pending' };
    },
    async copyTask() {
      calls.order.push('copyTask');
      calls.copyTask++;
      return { task: { copied: true } };
    },
    async resolveSourceChange(_p: number | string, _i: number | string, _b: number | string, unitId: number | string, input: SourceChangeInput) {
      calls.order.push('sourceChange:' + input.action);
      calls.sourceChange.push({ unitId, action: input.action });
      workspace = {
        ...workspace,
        units: workspace.units.map((u) =>
          String(u.id) === String(unitId)
            ? input.action === 'keep'
              ? { ...u, source_changed: false, source_change_summary: null }
              : { ...u, source_changed: false, source_change_summary: null, status: 'needs_revision' }
            : u,
        ),
      };
      return { ok: true };
    },
  };
  return {
    client,
    calls,
    getWorkspace: () => workspace,
    setFailSave: (v: boolean) => {
      failSave = v;
    },
  };
}

test('加载后选择为空；勾选为前端本地状态；重新 GET 后选择清空', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();
  assert.equal(editor.selection.value.selected, 0);
  assert.equal(editor.selection.value.total, 4);

  editor.toggleUnit('u1');
  editor.toggleUnit('u3');
  assert.deepEqual([...editor.selectedUnitIds.value].sort(), ['u1', 'u3']);
  assert.equal(editor.selection.value.selected, 2);

  // reload（重新 GET）后选择清空为正常行为
  await editor.load();
  assert.equal(editor.selection.value.selected, 0);
});

test('selectAll / clearSelection 维护本地选择', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();
  editor.selectAll();
  assert.equal(editor.allSelected.value, true);
  assert.equal(editor.selection.value.selected, 4);
  editor.clearSelection();
  assert.equal(editor.selection.value.selected, 0);
  assert.equal(editor.allSelected.value, false);
});

test('修改整批说明 / 备注 / 单页特殊要求 → dirty', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();
  assert.equal(editor.dirty.value, false);
  assert.equal(editor.saveState.value, 'saved');

  editor.updateBatchInstruction('新的整组说明');
  assert.equal(editor.dirty.value, true);
  assert.equal(editor.saveState.value, 'dirty');

  await editor.saveDraft();
  assert.equal(editor.dirty.value, false);
  assert.equal(editor.saveState.value, 'saved');

  editor.updateBatchNote('新的备注');
  assert.equal(editor.dirty.value, true);
  await editor.saveDraft();
  assert.equal(editor.dirty.value, false);

  editor.updateUnit('u2', { special_requirement: '新的特殊要求' });
  assert.equal(editor.dirty.value, true);
  await editor.saveDraft();
  assert.equal(editor.dirty.value, false);
});

test('run 前 dirty 自动先 PUT Draft，成功后执行（full 不依赖勾选）', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();
  editor.updateBatchInstruction('自动保存的整组说明');

  const ok = await editor.run('full');
  assert.equal(ok, true);
  assert.equal(f.calls.saveDraft, 1);
  assert.equal(f.calls.run, 1);
  // 顺序：saveDraft 先于 run
  assert.deepEqual(f.calls.order.slice(-3), ['saveDraft', 'run', 'load']);
  // full 不依赖勾选：unit_ids 为空，由后端解释为整批
  assert.deepEqual(f.calls.runInputs[0], { mode: 'full', unit_ids: [] });
  // run 成功后重新 GET（load 再次被调用），选择清空
  assert.equal(f.calls.load, 2);
  assert.equal(editor.selection.value.selected, 0);
});

test('PUT 失败不 run：明确提示保存失败', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();
  editor.updateBatchNote('会失败的备注');
  f.setFailSave(true);

  const ok = await editor.run('full');
  assert.equal(ok, false);
  assert.equal(f.calls.run, 0);
  assert.equal(editor.runPhase.value, 'error');
  assert.ok(editor.runError.value.includes('保存'), 'runError 应提示保存失败');
  assert.equal(editor.saveState.value, 'error');
});

test('copy-task 前 dirty 自动保存；保存失败不复制', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();

  editor.updateBatchNote('复制前自动保存');
  await editor.copyTask();
  assert.equal(f.calls.saveDraft, 1);
  assert.equal(f.calls.copyTask, 1);
  assert.equal(editor.copied.value, true);

  editor.updateBatchNote('再次修改');
  f.setFailSave(true);
  await editor.copyTask();
  assert.equal(f.calls.copyTask, 1, '保存失败时不得调用 copy-task');
});

test('trial / partial 依赖本地勾选；无勾选报错不执行', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();

  const trialOk = await editor.run('trial');
  assert.equal(trialOk, false);
  assert.equal(f.calls.run, 0);
  assert.ok(editor.runError.value.includes('勾选'), '应提示先勾选至少一页');

  editor.toggleUnit('u2');
  const trialOk2 = await editor.run('trial');
  assert.equal(trialOk2, true);
  assert.deepEqual(f.calls.runInputs[0], { mode: 'trial', unit_ids: ['u2'] });

  editor.clearSelection();
  const partialOk = await editor.run('partial');
  assert.equal(partialOk, false);
  assert.equal(f.calls.run, 1, 'partial 无勾选时不得调用 run');

  editor.selectAll();
  const partialOk2 = await editor.run('partial');
  assert.equal(partialOk2, true);
  assert.equal(f.calls.runInputs[1].mode, 'partial');
  assert.deepEqual(f.calls.runInputs[1].unit_ids, ['u1', 'u2', 'u3', 'u4']);
});

test('keep：调用正式 source-change API，成功后重新 GET，不乐观伪造', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();
  assert.equal(editor.anySourceChange.value, true);

  const ok = await editor.keepCurrent('u4');
  assert.equal(ok, true);
  assert.deepEqual(f.calls.sourceChange, [{ unitId: 'u4', action: 'keep' }]);
  // 成功后重新 GET（load 调用 +1）
  assert.equal(f.calls.load, 2);
  // 服务端返回最新状态：source_changed 已清
  assert.equal(editor.anySourceChange.value, false);
});

test('regenerate：调用正式 source-change API（action=regenerate）并刷新', async () => {
  const f = createFake();
  const editor = useImageBatch(1, 1, 1, f.client);
  await editor.load();

  const ok = await editor.regenerate('u4');
  assert.equal(ok, true);
  assert.deepEqual(f.calls.sourceChange, [{ unitId: 'u4', action: 'regenerate' }]);
  assert.equal(f.calls.load, 2);
  const u4 = editor.units.value.find((u) => u.id === 'u4');
  assert.equal(u4?.status, 'needs_revision');
  assert.equal(editor.anySourceChange.value, false);
});
