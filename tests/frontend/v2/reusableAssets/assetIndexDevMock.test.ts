// V2-P3-C06 DEV Mock 测试（node:test）。
// 验证固定示例数据覆盖缺失 / 无缩略图 / 从未使用 / 长名称边界，
// 以及内存 Mock client 只实现 GET 且返回 ApiResponse 包装。
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

test('第一阶段 mock client 没有任何写操作（无上传/编辑/生成）', () => {
  const mock = createMockAssetIndexClient();
  assert.equal(typeof mock.client.loadWorkspace, 'function');
  assert.equal((mock.client as unknown as Record<string, unknown>).prepare, undefined);
  assert.equal((mock.client as unknown as Record<string, unknown>).upload, undefined);
  assert.equal((mock.client as unknown as Record<string, unknown>).save, undefined);
});
