// V2-P3-C06 useReusableAssetIndex / API adapter / 生产 Mock 隔离测试（node:test）。
//
// 运行：
//   node --experimental-strip-types --import ./tests/frontend/v2/reusableAssets/register-loader.mjs \
//     --test tests/frontend/v2/reusableAssets/
//
// （import 跨模块无扩展名，需 ts-extension-loader；见 register-loader.mjs）
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { useReusableAssetIndex } from '../../../../resources/js/composables/v2/reusableAssets/useReusableAssetIndex.ts';
import { createAssetIndexClient } from '../../../../resources/js/composables/v2/reusableAssets/assetIndexApi.ts';
import { buildMockWorkspace } from '../../../../resources/js/composables/v2/reusableAssets/assetIndexDevMock.ts';
import {
  PREPARE_EVENT_BY_TYPE,
  VIEW_EVENT_BY_TYPE,
} from '../../../../resources/js/types/v2/reusableAssets/reusableAssets.ts';
import type { AssetIndexClient } from '../../../../resources/js/composables/v2/reusableAssets/assetIndexApi.ts';

function fakeClient(ws: ReturnType<typeof buildMockWorkspace>, trace?: { url?: string }): AssetIndexClient {
  return {
    async loadWorkspace(projectId) {
      if (trace) trace.url = `/api/projects/${Number(projectId)}/reusable-assets`;
      return structuredClone(ws);
    },
  };
}

const failingClient: AssetIndexClient = {
  async loadWorkspace() {
    throw new Error('network down');
  },
};

// ── API adapter ────────────────────────────────────────────────

test('typed API adapter：GET /api/projects/{project}/reusable-assets 并解包 ApiResponse', async () => {
  const ws = buildMockWorkspace();
  const requested: string[] = [];
  const client = createAssetIndexClient({
    async get(url: string) {
      requested.push(url);
      return { data: { data: ws } };
    },
  });
  const result = await client.loadWorkspace(42);
  assert.deepEqual(requested, ['/api/projects/42/reusable-assets']);
  assert.equal(result.characters.length, ws.characters.length);
  assert.equal(result.scenes.length, ws.scenes.length);
});

// ── composable：loading / 成功 / 错误 ─────────────────────────

test('初始 loading 为 true，加载完成后为 false 且数据就位', async () => {
  const ws = buildMockWorkspace();
  const index = useReusableAssetIndex(1, fakeClient(ws));
  assert.equal(index.loading.value, true);
  await index.load();
  assert.equal(index.loading.value, false);
  assert.equal(index.loadError.value, '');
  assert.equal(index.characters.value.length, 6);
  assert.equal(index.scenes.value.length, 5);
});

test('加载失败：loadError 有值、loading false、workspace 仍为空', async () => {
  const index = useReusableAssetIndex(1, failingClient);
  await index.load();
  assert.equal(index.loading.value, false);
  assert.ok(index.loadError.value.includes('network down'));
  assert.equal(index.workspace.value, null);
});

// ── Tab 与筛选 ─────────────────────────────────────────────────

test('Tab 切换：人物 → 场景', async () => {
  const index = useReusableAssetIndex(1, fakeClient(buildMockWorkspace()));
  await index.load();
  assert.equal(index.tab.value, 'character');
  assert.equal(index.tabItems.value.length, 6);
  index.setTab('scene');
  assert.equal(index.tab.value, 'scene');
  assert.equal(index.tabItems.value.length, 5);
});

test('状态筛选：全部 / 已有 / 缺失', async () => {
  const index = useReusableAssetIndex(1, fakeClient(buildMockWorkspace()));
  await index.load();
  const missingChars = index.characterCounts.value.missing;
  index.setStatusFilter('missing');
  assert.ok(missingChars > 0);
  assert.equal(index.visibleItems.value.length, missingChars);
  assert.ok(index.visibleItems.value.every((i) => i.status === 'missing'));
  index.setStatusFilter('existing');
  assert.ok(index.visibleItems.value.every((i) => i.status === 'existing'));
  index.setStatusFilter('all');
  assert.equal(index.visibleItems.value.length, index.tabItems.value.length);
});

test('空状态：workspace 为空 + all 筛选时 isEmpty 为 true', async () => {
  const empty = { characters: [], scenes: [] };
  const index = useReusableAssetIndex(1, fakeClient(empty));
  assert.equal(index.isEmpty.value, false); // loading 中
  await index.load();
  assert.equal(index.isEmpty.value, true);
});

test('筛选无结果时 isEmpty 为 true（如场景无缺失项）', async () => {
  const ws = buildMockWorkspace();
  ws.scenes = ws.scenes.filter((s) => s.status === 'existing'); // 场景全已有
  const index = useReusableAssetIndex(1, fakeClient(ws));
  await index.load();
  index.setTab('scene');
  index.setStatusFilter('missing');
  assert.equal(index.visibleItems.value.length, 0);
  assert.equal(index.isEmpty.value, true);
});

test('composable 不调用任何写操作（准备建立只是组件 emit）', async () => {
  const index = useReusableAssetIndex(1, fakeClient(buildMockWorkspace()));
  await index.load();
  assert.equal(typeof index.load, 'function');
  assert.equal(typeof index.setTab, 'function');
  assert.equal(typeof index.setStatusFilter, 'function');
  assert.equal((index as unknown as Record<string, unknown>).prepare, undefined);
});

// ── 事件契约（prepare / view） ────────────────────────────────

test('prepare 事件名契约：prepareCharacter / prepareScene', () => {
  assert.equal(PREPARE_EVENT_BY_TYPE.character, 'prepareCharacter');
  assert.equal(PREPARE_EVENT_BY_TYPE.scene, 'prepareScene');
});

test('view 事件名契约：viewCharacter / viewScene', () => {
  assert.equal(VIEW_EVENT_BY_TYPE.character, 'viewCharacter');
  assert.equal(VIEW_EVENT_BY_TYPE.scene, 'viewScene');
});

// ── 生产 Mock 0 引用（静态检查） ──────────────────────────────

const here = fileURLToPath(new URL('.', import.meta.url));
const srcDir = `${here}../../../../resources/js/composables/v2/reusableAssets/`;

test('生产模块（assetIndexApi / assetIndexCore / useReusableAssetIndex）0 引用 DevMock', () => {
  for (const file of ['assetIndexApi.ts', 'assetIndexCore.ts', 'useReusableAssetIndex.ts']) {
    const src = readFileSync(srcDir + file, 'utf8');
    assert.ok(!/DevMock|assetIndexDevMock|buildMockWorkspace/.test(src), `${file} 不应引用 DEV Mock`);
  }
});
