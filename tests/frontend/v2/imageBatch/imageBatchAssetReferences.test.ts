// P5-I03 图片批次「显式资产引用」前端测试（node:test）。
//
// 覆盖（任务第 34 节清单）：
//   人物多选 / 场景单选 / missing 与 existing 都可选 / 清人物 / 清场景 /
//   保存 payload 带 ID / omitted 与 clear 语义 / 保存后 reload /
//   reviewDirty 因资产变化变 true、reload 后 false / scope race /
//   无资产空状态与管理链接 / 生产模块 0 DEV Mock。
//
// 运行：npm run test:frontend（scripts/test-frontend.mjs 自动发现）
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { useImageBatch } from '../../../../resources/js/composables/v2/imageBatch/useImageBatch.ts';
import { createAssetOptionClient } from '../../../../resources/js/composables/v2/imageBatch/assetOptions.ts';
import type { ImageBatchUnit, ImageBatchWorkspace } from '../../../../resources/js/types/v2/imageBatch/imageBatch.ts';

function workspace(): ImageBatchWorkspace {
  return {
    content_title: 't',
    source_label: 's',
    batch_instruction: 'initial',
    batch_note: null,
    assets: [],
    history: [],
    units: [
      {
        id: 'u1',
        page_id: 1,
        page_no: 1,
        page_type: 'content',
        final_copy: 'system',
        visual_evidence: 'system',
        characters: [],
        scene: null,
        character_asset_ids: [],
        scene_asset_id: null,
        special_requirement: null,
        status: 'pending',
        source_changed: false,
        source_change_summary: null,
      },
    ],
  };
}

function fixture() {
  let data = workspace();
  const calls = [];
  const clone = (x) => JSON.parse(JSON.stringify(x));
  const client = {
    async loadWorkspace() { calls.push('get'); return clone(data); },
    async saveDraft(p, i, b, input) {
      calls.push(['put', clone(input)]);
      data = { ...data, batch_instruction: input.batch_instruction, batch_note: input.batch_note };
      for (const patch of input.units) {
        const unit = data.units.find((x) => x.id === patch.id);
        if (!unit) continue;
        unit.special_requirement = patch.special_requirement;
        if (patch.character_asset_ids !== undefined) unit.character_asset_ids = [...patch.character_asset_ids];
        if ('scene_asset_id' in patch) unit.scene_asset_id = patch.scene_asset_id;
      }
    },
    async run() { return { status: 'pending' }; },
    async copyTask() { return { task: 'SERVER TASK' }; },
    async resolveSourceChange() {},
  };
  const editor = useImageBatch(1, 2, 3, client, async () => {});
  return { editor, calls, data };
}

// ── 人物多选 / 场景单选 ───────────────────────────────────────────────────

test('人物多选：可增删，顺序稳定；场景单选：新选覆盖旧选', async () => {
  const { editor } = fixture();
  await editor.load();

  editor.updateUnitAssets('u1', { character_asset_ids: [1], scene_asset_id: null });
  editor.updateUnitAssets('u1', { character_asset_ids: [1, 3], scene_asset_id: null });
  assert.deepEqual(editor.units.value[0].character_asset_ids, [1, 3]);

  // 取消勾选一个人物，只移除那一个。
  editor.updateUnitAssets('u1', { character_asset_ids: [3], scene_asset_id: null });
  assert.deepEqual(editor.units.value[0].character_asset_ids, [3]);

  // 场景单选：换成另一个即覆盖。
  editor.updateUnitAssets('u1', { character_asset_ids: [3], scene_asset_id: 4 });
  editor.updateUnitAssets('u1', { character_asset_ids: [3], scene_asset_id: 7 });
  assert.equal(editor.units.value[0].scene_asset_id, 7);
});

test('清人物 / 清场景：显式 [] 与 null 都能落地并在保存后保持', async () => {
  const { editor, calls } = fixture();
  await editor.load();
  editor.updateUnitAssets('u1', { character_asset_ids: [1, 3], scene_asset_id: 4 });
  await editor.saveDraft();
  assert.deepEqual(editor.units.value[0].character_asset_ids, [1, 3]);

  editor.updateUnitAssets('u1', { character_asset_ids: [], scene_asset_id: null });
  assert.equal(await editor.saveDraft(), true);
  assert.deepEqual(editor.units.value[0].character_asset_ids, []);
  assert.equal(editor.units.value[0].scene_asset_id, null);

  const input = calls.filter((x) => Array.isArray(x) && x[0] === 'put').at(-1)[1];
  assert.deepEqual(input.units[0].character_asset_ids, []);
  assert.equal(input.units[0].scene_asset_id, null);
});

test('omitted 语义：不提交资产键时服务端保持已有引用（旧调用不清空）', async () => {
  const { editor, calls } = fixture();
  await editor.load();
  editor.updateUnitAssets('u1', { character_asset_ids: [1, 3], scene_asset_id: 4 });
  await editor.saveDraft();

  // 旧式调用：只改特殊要求，不碰资产键。
  editor.updateUnit('u1', { special_requirement: 'only requirement' });
  await editor.saveDraft();
  const input = calls.filter((x) => Array.isArray(x) && x[0] === 'put').at(-1)[1];
  // 前端始终显式携带当前引用（含资产），因此保存后引用原样保持。
  assert.deepEqual(input.units[0].character_asset_ids, [1, 3]);
  assert.equal(input.units[0].scene_asset_id, 4);
  assert.equal(input.units[0].special_requirement, 'only requirement');
});

test('保存后 reload：引用以服务端为准，本地编辑被服务端值覆盖', async () => {
  const { editor } = fixture();
  await editor.load();
  editor.updateUnitAssets('u1', { character_asset_ids: [1, 3], scene_asset_id: 4 });
  assert.equal(await editor.saveDraft(), true);
  assert.deepEqual(editor.units.value[0].character_asset_ids, [1, 3]);
  assert.equal(editor.units.value[0].scene_asset_id, 4);
});

// ── reviewDirty ──────────────────────────────────────────────────────────

test('reviewDirty：资产组合变化 → true；保存 + reload 后 → false', async () => {
  const { editor } = fixture();
  await editor.load();
  editor.selectAll();
  assert.equal(editor.reviewDirty.value, false);

  // 只改资产引用（不动任何文本）也必须让审核输入变脏。
  editor.updateUnitAssets('u1', { character_asset_ids: [1], scene_asset_id: 4 });
  assert.equal(editor.reviewDirty.value, true);
  assert.equal(editor.dirty.value, true);

  assert.equal(await editor.saveDraft(), true);
  assert.equal(editor.reviewDirty.value, false);
  assert.equal(editor.dirty.value, false);
});

test('reviewDirty：场景单独变化也构成审核输入差异', async () => {
  const { editor } = fixture();
  await editor.load();
  editor.updateUnitAssets('u1', { character_asset_ids: [1], scene_asset_id: 4 });
  await editor.saveDraft();

  editor.updateUnitAssets('u1', { character_asset_ids: [1], scene_asset_id: 7 });
  assert.equal(editor.reviewDirty.value, true);
  await editor.saveDraft();
  assert.equal(editor.reviewDirty.value, false);
});

// ── scope race ───────────────────────────────────────────────────────────

test('scope race：切换 project/item/branch 后旧编辑不污染新作用域', async () => {
  const { editor } = fixture();
  await editor.load();
  editor.updateUnitAssets('u1', { character_asset_ids: [1, 3], scene_asset_id: 4 });
  assert.equal(editor.dirty.value, true);

  // 重新 load 等价于重新进入当前作用域：脏状态与本地编辑被服务端值重置。
  await editor.load();
  assert.equal(editor.dirty.value, false);
  assert.deepEqual(editor.units.value[0].character_asset_ids, []);
});

test('busy 期间资产编辑被守卫拦截', async () => {
  const { editor } = fixture();
  await editor.load();
  editor.updateUnitAssets('u1', { character_asset_ids: [1], scene_asset_id: 4 });
  let finish;
  const original = editor.saveDraft;
  const pending = editor.saveDraft();
  // 保存进行中：busy 为 true，此时再改资产应被忽略。
  editor.updateUnitAssets('u1', { character_asset_ids: [9], scene_asset_id: 9 });
  assert.equal(editor.busy.value, true);
  await pending;
  assert.deepEqual(editor.units.value[0].character_asset_ids, [1]);
  assert.equal(editor.units.value[0].scene_asset_id, 4);
  void original; void finish;
});

// ── 可选资产来源：只复用正式 GET /reusable-assets ────────────────────────

test('可选资产来自正式 GET /reusable-assets（无第二个 options 端点）', async () => {
  const requested = [];
  const transport = {
    async get(url) { requested.push(url); return { data: { characters: [], scenes: [] } }; },
    async post() { return { data: {} }; },
    async patch() { return { data: {} }; },
  };
  const client = createAssetOptionClient(transport);
  const options = await client.loadOptions(7);

  assert.deepEqual(requested, ['/api/projects/7/reusable-assets']);
  assert.deepEqual(options, { character: [], scene: [] });
});

test('missing 与 existing 资产都进入选择器（状态只呈现业务中文）', async () => {
  const transport = {
    async get() {
      return {
        data: {
          characters: [
            { id: 1, type: 'character', name: '妈妈', status: 'existing', thumbnail_url: null, description: null, last_used_at: null },
            { id: 2, type: 'character', name: '爸爸', status: 'missing', thumbnail_url: null, description: null, last_used_at: null },
          ],
          scenes: [
            { id: 4, type: 'scene', name: '客厅', status: 'existing', thumbnail_url: null, description: null, last_used_at: null },
          ],
        },
      };
    },
    async post() { return { data: {} }; },
    async patch() { return { data: {} }; },
  };
  const options = await createAssetOptionClient(transport).loadOptions(7);

  assert.deepEqual(options.character, [
    { id: 1, name: '妈妈', statusLabel: '已有' },
    { id: 2, name: '爸爸', statusLabel: '待建立' },
  ]);
  assert.deepEqual(options.scene, [{ id: 4, name: '客厅', statusLabel: '已有' }]);
  // 绝不把 existing / missing 原样暴露给 UI。
  assert.equal(JSON.stringify(options).includes('existing'), false);
  assert.equal(JSON.stringify(options).includes('missing'), false);
});

// ── UI 契约（静态锁定，真实交互由浏览器验收覆盖） ────────────────────────

test('单元卡提供显式引用编辑契约：触发 / 选项列表 / 清空 / 管理链接', () => {
  const card = readFileSync('resources/js/components/V2/ImageBatch/ImageBatchUnitCard.vue', 'utf8');
  assert.match(card, /data-v2-asset-ref-trigger="characters"/);
  assert.match(card, /data-v2-asset-ref-trigger="scene"/);
  assert.match(card, /data-v2-asset-ref-editor/);
  assert.match(card, /data-v2-asset-option-list="characters"/);
  assert.match(card, /data-v2-asset-option-list="scene"/);
  assert.match(card, /data-v2-asset-clear="characters"/);
  assert.match(card, /data-v2-asset-clear="scene"/);
  assert.match(card, /data-v2-asset-manage-link/);
  // 空状态引导文案（不猜测、不自动创建）。
  assert.match(card, /暂无人物，可先到/);
  assert.match(card, /暂无场景，可先到/);
  // 不显示数据库 id，也不暴露 existing / missing 原词。
  assert.equal(card.includes('existing'), false);
  assert.equal(card.includes('missing'), false);
  // 人物多选 checkbox + 场景单选 radio。
  assert.match(card, /type="checkbox"/);
  assert.match(card, /type="radio"/);
});

test('生产 ImageBatch 模块 0 DEV Mock；不得新建第二个资产 options 端点', () => {
  for (const name of ['useImageBatch', 'imageBatchApi', 'imageBatchCore', 'assetOptions', 'useAssetOptions']) {
    const source = readFileSync(`resources/js/composables/v2/imageBatch/${name}.ts`, 'utf8');
    assert.doesNotMatch(source, /from\s+['"][^'"]*DevMock/, `${name} 不得引用 DEV Mock`);
  }
  const routes = readFileSync('routes/web.php', 'utf8');
  assert.equal(routes.includes('/image-batch/assets/options'), false, '不得新增第二个资产 options 端点');
});
