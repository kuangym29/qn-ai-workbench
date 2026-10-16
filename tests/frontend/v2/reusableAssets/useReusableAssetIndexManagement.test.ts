// P5-I02 复用资产「轻量资产管理」composable 测试（node:test）。
//
// 覆盖：新增人物 / 新增场景 / duplicate 冲突 / 编辑 status / 编辑 description /
//       清空 thumbnail / missing → existing / 保存后重新加载正式数据 /
//       错误恢复 / scope change 不污染旧 Project。
//
// 运行：npm run test:frontend（scripts/test-frontend.mjs 自动发现）
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { useReusableAssetIndex } from '../../../../resources/js/composables/v2/reusableAssets/useReusableAssetIndex.ts';
import type { AssetIndexClient } from '../../../../resources/js/composables/v2/reusableAssets/assetIndexApi.ts';
import {
  buildAssetCreatePayload,
  buildAssetUpdatePayload,
} from '../../../../resources/js/composables/v2/reusableAssets/assetIndexCore.ts';
import type {
  AssetEditorForm,
  ReusableAssetItem,
  ReusableAssetWorkspace,
} from '../../../../resources/js/types/v2/reusableAssets/reusableAssets.ts';

function item(over: Partial<ReusableAssetItem> = {}): ReusableAssetItem {
  return {
    id: 1,
    type: 'character',
    name: '妈妈',
    status: 'missing',
    thumbnail_url: null,
    description: null,
    last_used_at: null,
    ...over,
  };
}

function workspace(over: Partial<ReusableAssetWorkspace> = {}): ReusableAssetWorkspace {
  return { characters: [item()], scenes: [], ...over };
}

interface Trace {
  calls: string[];
  created: unknown[];
  updated: { id: unknown; payload: unknown }[];
}

function stubClient(
  ws: ReusableAssetWorkspace,
  options: { failCreate?: string; failUpdate?: string } = {},
): { client: AssetIndexClient; trace: Trace; setWorkspace: (w: ReusableAssetWorkspace) => void } {
  let current = ws;
  const trace: Trace = { calls: [], created: [], updated: [] };

  const client = {
    async loadWorkspace() {
      trace.calls.push('loadWorkspace');
      return structuredClone(current);
    },
    async createAsset(_projectId: unknown, payload: unknown) {
      trace.calls.push('createAsset');
      trace.created.push(payload);
      if (options.failCreate) throw new Error(options.failCreate);
      return item();
    },
    async updateAsset(_projectId: unknown, id: unknown, payload: unknown) {
      trace.calls.push('updateAsset');
      trace.updated.push({ id, payload });
      if (options.failUpdate) throw new Error(options.failUpdate);
      return item();
    },
  } as unknown as AssetIndexClient;

  return { client, trace, setWorkspace: (w) => { current = w; } };
}

const form = (over: Partial<AssetEditorForm> = {}): AssetEditorForm => ({
  name: '妈妈',
  status: 'missing',
  thumbnail_url: '',
  description: '',
  ...over,
});

// ── 新增 ─────────────────────────────────────────────────────

test('新增人物：POST 后重新加载正式 GET 数据（不依赖 optimistic cache）', async () => {
  const { client, trace, setWorkspace } = stubClient(workspace());
  const index = useReusableAssetIndex(7, client);
  await index.load();
  assert.equal(index.characters.value.length, 1);

  setWorkspace(workspace({ characters: [item(), item({ id: 2, name: '爸爸' })] }));
  const created = await index.createAsset(buildAssetCreatePayload('character', form({ name: '爸爸' })));

  assert.deepEqual(trace.created[0], {
    type: 'character', name: '爸爸', status: 'missing', thumbnail_url: null, description: null,
  });
  assert.deepEqual(trace.calls, ['loadWorkspace', 'createAsset', 'loadWorkspace'], '保存成功后必须重新拉取');
  assert.equal(index.characters.value.length, 2, '主列表已按服务器数据刷新');
  assert.equal(created.type, 'character');
  assert.equal(index.saveError.value, '');
});

test('新增场景：载荷 type 为 scene', async () => {
  const { client, trace } = stubClient(workspace({ characters: [], scenes: [] }));
  const index = useReusableAssetIndex(1, client);
  await index.load();

  await index.createAsset(buildAssetCreatePayload('scene', form({ name: '客厅', status: 'existing' })));

  assert.deepEqual((trace.created[0] as Record<string, unknown>).type, 'scene');
  assert.equal((trace.created[0] as Record<string, unknown>).name, '客厅');
});

test('duplicate 冲突：saveError 呈现同名提示，且不重新加载（列表不被污染）', async () => {
  const { client, trace } = stubClient(workspace(), { failCreate: '同名人物已存在（妈妈）。' });
  const index = useReusableAssetIndex(1, client);
  await index.load();

  await assert.rejects(() => index.createAsset(buildAssetCreatePayload('character', form())));

  assert.equal(index.saveError.value, '同名人物已存在（妈妈）。');
  assert.deepEqual(trace.calls, ['loadWorkspace', 'createAsset'], '失败不得触发 reload');
  assert.equal(index.saving.value, false);
});

// ── 编辑 ─────────────────────────────────────────────────────

test('编辑 status：missing → existing（明确保存，不自动切换）', async () => {
  const { client, trace } = stubClient(workspace());
  const index = useReusableAssetIndex(1, client);
  await index.load();

  await index.updateAsset(1, buildAssetUpdatePayload(form({ status: 'existing' })));

  assert.equal(trace.updated[0].id, 1);
  assert.equal((trace.updated[0].payload as Record<string, unknown>).status, 'existing');
  assert.deepEqual(trace.calls, ['loadWorkspace', 'updateAsset', 'loadWorkspace']);
});

test('编辑 description：下发文本并在保存后重新加载', async () => {
  const { client, trace } = stubClient(workspace());
  const index = useReusableAssetIndex(1, client);
  await index.load();

  await index.updateAsset(1, buildAssetUpdatePayload(form({ description: '固定身份说明' })));

  assert.equal((trace.updated[0].payload as Record<string, unknown>).description, '固定身份说明');
});

test('清空 thumbnail：显式 null 下发', async () => {
  const { client, trace } = stubClient(workspace({ characters: [item({ thumbnail_url: 'https://cdn.test/a.png' })] }));
  const index = useReusableAssetIndex(1, client);
  await index.load();

  await index.updateAsset(1, buildAssetUpdatePayload(form({ thumbnail_url: '   ' })));

  const payload = trace.updated[0].payload as Record<string, unknown>;
  assert.ok('thumbnail_url' in payload, '清空必须下发显式键');
  assert.equal(payload.thumbnail_url, null);
});

test('编辑载荷绝不包含 name / type（稳定身份）', async () => {
  const { client, trace } = stubClient(workspace());
  const index = useReusableAssetIndex(1, client);
  await index.load();

  await index.updateAsset(1, buildAssetUpdatePayload(form({ name: '改个名' })));

  const payload = trace.updated[0].payload as Record<string, unknown>;
  assert.equal('name' in payload, false);
  assert.equal('type' in payload, false);
});

// ── 错误恢复 ──────────────────────────────────────────────────

test('错误恢复：首次失败后重试成功，saveError 清空且列表刷新', async () => {
  let fail = true;
  const trace: string[] = [];
  const client = {
    async loadWorkspace() { trace.push('loadWorkspace'); return workspace(); },
    async createAsset() { trace.push('createAsset'); if (fail) throw new Error('同名人物已存在。'); return item(); },
    async updateAsset() { trace.push('updateAsset'); return item(); },
  } as unknown as AssetIndexClient;

  const index = useReusableAssetIndex(1, client);
  await index.load();

  await assert.rejects(() => index.createAsset(buildAssetCreatePayload('character', form())));
  assert.equal(index.saveError.value, '同名人物已存在。');

  index.clearSaveError();
  assert.equal(index.saveError.value, '');

  fail = false;
  await index.createAsset(buildAssetCreatePayload('character', form({ name: '爸爸' })));
  assert.equal(index.saveError.value, '');
  assert.deepEqual(trace, ['loadWorkspace', 'createAsset', 'createAsset', 'loadWorkspace']);
});

// ── scope change ─────────────────────────────────────────────

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => { resolve = r; });
  return { promise, resolve };
}

test('scope change：旧 Project 的慢响应不得覆盖新 Project 数据', async () => {
  const slow = deferred<ReusableAssetWorkspace>();
  const fast = deferred<ReusableAssetWorkspace>();

  let project: string | number = 'A';
  const client = {
    loadWorkspace(id: unknown) { return id === 'A' ? slow.promise : fast.promise; },
    async createAsset() { return item(); },
    async updateAsset() { return item(); },
  } as unknown as AssetIndexClient;

  const index = useReusableAssetIndex(() => project, client);

  const first = index.load();          // Project A（慢）
  project = 'B';
  const second = index.load();         // Project B（快）

  fast.resolve({ characters: [item({ id: 99, name: 'B 的人物' })], scenes: [] });
  await second;

  slow.resolve({ characters: [item({ id: 1, name: 'A 的人物' })], scenes: [] });
  await first;

  assert.equal(index.workspace.value?.characters.length, 1);
  assert.equal(index.characters.value[0].name, 'B 的人物', '过期响应必须被丢弃，不得污染当前 Project');
});
