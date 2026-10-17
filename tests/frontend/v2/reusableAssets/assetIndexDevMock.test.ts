// V2-P3-C06 / P5-I02 DEV Mock 测试（node:test）。
// 验证固定示例数据覆盖缺失 / 无缩略图 / 从未使用 / 长名称边界，
// 以及内存 Mock client 实现 GET + POST + PATCH（无 DELETE）、
// 且 GET 返回 ApiResponse 包装。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildMockWorkspace, createMockAssetIndexClient } from '../../../../resources/js/composables/v2/reusableAssets/assetIndexDevMock.ts';
import type {
  ReusableAssetItem,
  ReusableAssetType,
} from '../../../../resources/js/types/v2/reusableAssets/reusableAssets.ts';

const namesOf = (items: ReusableAssetItem[], type: ReusableAssetType) =>
  items.filter((i) => i.type === type).map((i) => i.name);

test('人物只收固定身份（爸爸/妈妈/姐姐/弟弟/爷爷/奶奶）', () => {
  const ws = buildMockWorkspace();
  assert.equal(ws.characters.length, 6);
  assert.deepEqual(
    [...ws.characters].sort((a, b) => a.name.localeCompare(b.name, 'zh')).map((c) => c.name),
    ['爷爷', '奶奶', '妈妈', '姐姐', '弟弟', '爸爸'].sort((a, b) => a.localeCompare(b, 'zh')),
  );
});

test('不出现路人 / 一次性角色 / 背景人物', () => {
  const ws = buildMockWorkspace();
  const names = namesOf(ws.characters, 'character').join('|');
  assert.ok(!/路人|背景|一次性|陌生|顾客|路人甲/.test(names));
});

test('场景只保存稳定母版，不出现时间/光线/天气/道具变体', () => {
  const ws = buildMockWorkspace();
  const names = namesOf(ws.scenes, 'scene').join('|');
  assert.ok(!/早晨|夜晚|晚上|早上|下雨|雨天|杯子|台灯|多了|位置/.test(names));
  assert.ok(ws.scenes.some((s) => s.name === '客厅'));
  assert.ok(ws.scenes.some((s) => s.name === '儿童房'));
});

test('existing / missing 两态都有，且没有第三种状态', () => {
  const ws = buildMockWorkspace();
  const all = [...ws.characters, ...ws.scenes];
  const statuses = new Set(all.map((i) => i.status));
  assert.ok(statuses.has('existing'));
  assert.ok(statuses.has('missing'));
  assert.ok(all.every((i) => i.status === 'existing' || i.status === 'missing'));
});

test('存在无缩略图（thumbnail_url = null）的资产', () => {
  const ws = buildMockWorkspace();
  const all = [...ws.characters, ...ws.scenes];
  assert.ok(all.some((i) => i.thumbnail_url === null));
  // 有图的均为内联 data URI（无外链）
  assert.ok(all.filter((i) => i.thumbnail_url).every((i) => i.thumbnail_url!.startsWith('data:image/svg+xml')));
});

test('存在从未使用（last_used_at = null）的资产', () => {
  const ws = buildMockWorkspace();
  const all = [...ws.characters, ...ws.scenes];
  assert.ok(all.some((i) => i.last_used_at === null));
});

test('存在长名称场景（用于 truncate 验收）', () => {
  const ws = buildMockWorkspace();
  assert.ok(ws.scenes.some((s) => s.name.length >= 5));
});

test('mock client GET 走正式端点形状并解包 ApiResponse', async () => {
  const mock = createMockAssetIndexClient();
  const result = await mock.client.loadWorkspace(7);
  assert.ok(result.characters.length > 0);
  assert.equal(mock.calls.length, 1);
  assert.deepEqual(mock.calls[0], { method: 'GET', url: '/api/projects/7/reusable-assets' });
});

test('mock client 返回的是克隆，调用方修改不污染 store', async () => {
  const mock = createMockAssetIndexClient();
  const first = await mock.client.loadWorkspace(1);
  first.characters.pop();
  const second = await mock.client.loadWorkspace(1);
  assert.equal(second.characters.length, 6);
});

test('P5-I02 mock client 只提供索引读写，绝不提供删除 / 上传 / 生成', () => {
  const mock = createMockAssetIndexClient();
  assert.equal(typeof mock.client.loadWorkspace, 'function');
  assert.equal(typeof mock.client.createAsset, 'function');
  assert.equal(typeof mock.client.updateAsset, 'function');

  for (const forbidden of ['prepare', 'upload', 'save', 'remove', 'delete', 'destroy', 'rename', 'generate']) {
    assert.equal((mock.client as unknown as Record<string, unknown>)[forbidden], undefined, `不得提供 ${forbidden}`);
  }
});

test('P5-I02 mock client 内存支持 POST（新增）与 PATCH（编辑 / 清空）', async () => {
  const mock = createMockAssetIndexClient();

  const created = await mock.client.createAsset(1, {
    type: 'character', name: '舅舅', status: 'missing', thumbnail_url: null, description: null,
  });
  assert.equal(created.name, '舅舅');
  assert.equal(created.last_used_at, null, 'create 不得写 last_used_at');
  assert.deepEqual(mock.calls.at(-1), { method: 'POST', url: '/api/projects/1/reusable-assets' });

  const updated = await mock.client.updateAsset(1, created.id, { status: 'existing', thumbnail_url: null, description: '补充' });
  assert.equal(updated.status, 'existing');
  assert.equal(updated.description, '补充');
  assert.deepEqual(mock.calls.at(-1), { method: 'PATCH', url: `/api/projects/1/reusable-assets/${created.id}` });

  // 同名冲突
  await assert.rejects(
    () => mock.client.createAsset(1, { type: 'character', name: '舅舅', status: 'existing' }),
    /同名人物已存在/,
  );
});
