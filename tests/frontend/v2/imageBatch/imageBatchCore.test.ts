// V2-P3-C05-R1 图片批次核心纯函数测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/imageBatch/imageBatchCore.test.ts
// 说明：DEV Mock 已隔离到 imageBatchDevMock.ts，其测试位于 imageBatchDevMock.test.ts；
// 本文件只测正式生产核心纯函数（标签 / 资产分组 / 选择 / 执行目标 / Draft 载荷 / 来源变化）。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  assetStatusLabel,
  buildDraftPayload,
  executionLabel,
  executionTargets,
  groupAssets,
  hasHistory,
  hasSourceChange,
  historyStatusLabel,
  pageLabel,
  pageTypeLabel,
  selectedUnits,
  selectionSummary,
  sourceChangedUnits,
  unitStatusLabel,
} from '../../../../resources/js/composables/v2/imageBatch/imageBatchCore.ts';
import type {
  AssetCheck,
  ImageBatchUnit,
  ImageBatchWorkspace,
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

test('R1：ImageBatchUnit 正式合同不含 selected（勾选为前端本地状态）', () => {
  const unit = makeUnit();
  // 运行时断言：工作台单元对象不得携带 selected 字段（类型层面已由 TS 保证）。
  assert.ok(!('selected' in unit), 'ImageBatchUnit 不应包含 selected 字段');
  assert.equal(unit.selected as unknown, undefined);
});

test('executionLabel：三种执行方式中文标签', () => {
  assert.equal(executionLabel('full'), '整批生成');
  assert.equal(executionLabel('trial'), '先做试样');
  assert.equal(executionLabel('partial'), '选择部分页面生成');
});

test('unitStatusLabel：单页状态中文标签', () => {
  assert.equal(unitStatusLabel('pending'), '待制作');
  assert.equal(unitStatusLabel('generated'), '已生成');
  assert.equal(unitStatusLabel('needs_revision'), '需修改');
  assert.equal(unitStatusLabel('approved'), '已通过');
});

test('assetStatusLabel：资产状态中文标签', () => {
  assert.equal(assetStatusLabel('existing'), '已有');
  assert.equal(assetStatusLabel('missing'), '缺失');
  assert.equal(assetStatusLabel('new_in_batch'), '本批新建');
});

test('pageLabel / pageTypeLabel：页码与页面类型展示', () => {
  assert.equal(pageLabel(1), 'P1');
  assert.equal(pageLabel(10), 'P10');
  assert.equal(pageTypeLabel('cover'), '封面');
  assert.equal(pageTypeLabel('content'), '正文');
  assert.equal(pageTypeLabel('column_closing'), '栏目收尾');
  assert.equal(pageTypeLabel('fixed_back_cover'), '固定封底');
  assert.equal(pageTypeLabel('unknown_type'), 'unknown_type');
});

test('groupAssets：按人物 / 场景分组且保持顺序', () => {
  const assets: AssetCheck[] = [
    { type: 'character', name: '妈妈', status: 'existing' },
    { type: 'scene', name: '客厅', status: 'existing' },
    { type: 'character', name: '姐姐', status: 'missing' },
    { type: 'scene', name: '儿童房', status: 'new_in_batch' },
  ];
  const g = groupAssets(assets);
  assert.deepEqual(
    g.characters.map((a) => a.name),
    ['妈妈', '姐姐'],
  );
  assert.deepEqual(
    g.scenes.map((a) => a.name),
    ['客厅', '儿童房'],
  );
  assert.deepEqual(
    g.scenes.map((a) => a.status),
    ['existing', 'new_in_batch'],
  );
});

test('selectionSummary / selectedUnits：基于本地 selectedUnitIds，升序返回', () => {
  const units = [
    makeUnit({ id: 'u3', page_no: 3 }),
    makeUnit({ id: 'u1', page_no: 1 }),
    makeUnit({ id: 'u2', page_no: 2 }),
  ];
  const selectedIds = new Set(['u2', 'u3']);
  assert.deepEqual(selectionSummary(units, selectedIds), { selected: 2, total: 3 });
  assert.deepEqual(
    selectedUnits(units, selectedIds).map((u) => u.page_no),
    [2, 3],
  );
  assert.equal(selectionSummary(units, new Set()).selected, 0);
  assert.equal(selectedUnits(units, new Set()).length, 0);
});

test('executionTargets：整批生成覆盖全部；试样 / 部分生成按本地勾选；无勾选返回空', () => {
  const units = [
    makeUnit({ id: 'u1', page_no: 1 }),
    makeUnit({ id: 'u2', page_no: 2 }),
    makeUnit({ id: 'u3', page_no: 3 }),
  ];
  const selectedIds = new Set(['u1', 'u3']);
  assert.deepEqual(
    executionTargets(units, selectedIds, 'full').map((u) => u.page_no),
    [1, 2, 3],
  );
  assert.deepEqual(
    executionTargets(units, selectedIds, 'trial').map((u) => u.page_no),
    [1, 3],
  );
  assert.deepEqual(
    executionTargets(units, selectedIds, 'partial').map((u) => u.page_no),
    [1, 3],
  );
  assert.equal(executionTargets(units, new Set(), 'trial').length, 0);
  // full 不依赖勾选
  assert.equal(executionTargets(units, new Set(), 'full').length, 3);
});

test('buildDraftPayload：只发送用户可编辑的制作补充，不发送来源内容 / 系统继承字段', () => {
  const workspace: ImageBatchWorkspace = {
    content_title: '测试篇目',
    source_label: '栏目 › 来源',
    batch_instruction: '整组说明',
    batch_note: '备注',
    assets: [],
    units: [
      makeUnit({ id: 'u1', page_no: 1, special_requirement: '表情特写' }),
      makeUnit({ id: 'u2', page_no: 2, special_requirement: null }),
    ],
    history: [],
  };
  const draft = buildDraftPayload(workspace, workspace.units);
  assert.equal(draft.batch_instruction, '整组说明');
  assert.equal(draft.batch_note, '备注');
  assert.deepEqual(draft.units, [
    { id: 'u1', special_requirement: '表情特写' },
    { id: 'u2', special_requirement: null },
  ]);
  // Draft 单元不得携带 final_copy / visual_evidence / characters / scene / page_type
  for (const u of draft.units) {
    assert.ok(!('final_copy' in u));
    assert.ok(!('visual_evidence' in u));
    assert.ok(!('characters' in u));
    assert.ok(!('scene' in u));
    assert.ok(!('page_type' in u));
  }
});

test('hasSourceChange / sourceChangedUnits：来源变化提示', () => {
  const units = [
    makeUnit({ id: 'u1', page_no: 1, source_changed: false }),
    makeUnit({ id: 'u2', page_no: 2, source_changed: true }),
    makeUnit({ id: 'u3', page_no: 3, source_changed: true }),
  ];
  assert.equal(hasSourceChange(units), true);
  assert.deepEqual(
    sourceChangedUnits(units).map((u) => u.page_no),
    [2, 3],
  );
  assert.equal(hasSourceChange(units.map((u) => ({ ...u, source_changed: false }))), false);
  assert.equal(sourceChangedUnits([]).length, 0);
});

test('hasHistory / historyStatusLabel：历史批次判定与状态兜底', () => {
  assert.equal(hasHistory([{ id: 1 }]), true);
  assert.equal(hasHistory([]), false);
  assert.equal(hasHistory(null), false);
  assert.equal(hasHistory(undefined), false);
  assert.equal(historyStatusLabel('已通过'), '已通过');
  assert.equal(historyStatusLabel(''), '未知');
  assert.equal(historyStatusLabel(null), '未知');
});
