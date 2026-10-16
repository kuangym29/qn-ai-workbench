// I05 集成 —— 前端侧锁（真实合同，非 Mock）。
//
// 锁定：GET 体只解包一层（不再出现 data.data）、execute bodies 精确、
// copy 只用服务端 task、history 只能来自重新 GET、生产文件 0 Mock import、
// 资产空态有降级文案。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createImageBatchClient } from '../../../../resources/js/composables/v2/imageBatch/imageBatchApi.ts';

const BASE = '/api/projects/7/items/8/branches/9/image-batch';

function serverRef() {
  // 一份真实 GET 形状的工作台（字段等价于后端 ImageBatchWorkspace）。
  return {
    content_title: '柠檬 bear 的一天',
    source_label: '育儿 › 柠檬 bear 的一天',
    batch_instruction: '柠檬黄暖调',
    batch_note: null,
    assets: [],
    units: [
      {
        id: 11, page_id: 21, page_no: 1, page_type: 'cover',
        final_copy: '封面文案', visual_evidence: '画面证据',
        characters: [], scene: null, special_requirement: null,
        status: 'pending', source_changed: false, source_change_summary: null,
      },
    ],
    history: [],
  };
}

function transport(overrides: Record<string, (url: string, body?: unknown) => Promise<{ data: unknown }>> = {}) {
  const calls: unknown[] = [];
  const record = (kind: string) => async (url: string, body?: unknown) => {
    calls.push(['get', 'put', 'post'].includes(kind) && body === undefined ? [kind, url] : [kind, url, body]);
    const impl = overrides[kind];
    return impl ? await impl(url, body) : { data: {} };
  };

  return { calls, http: { get: record('get'), put: record('put'), post: record('post') } };
}

/** 去掉行注释与块注释：静态检查只看真实代码，避免注释里的名字造成误判。 */
function stripComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

test('GET contract: transport returns axios-shaped { data }; client unwraps exactly once', async () => {
  const workspace = serverRef();
  const { calls, http } = transport({ get: async () => ({ data: workspace }) });
  const api = createImageBatchClient(http);

  const result = await api.loadWorkspace(7, 8, 9);

  assert.deepEqual(result, workspace, '结果必须是 ImageBatchWorkspace 本体');
  assert.deepEqual(Object.keys(result).sort(), Object.keys(workspace).sort());
  assert.equal('data' in result, false, '不得保留 data 包裹以免前端出现 data.data');
  assert.deepEqual(calls[0], ['get', BASE]);
});

test('run bodies are exact for full / trial / partial through the production client', async () => {
  for (const [mode, ids] of [['full', []], ['trial', [11]], ['partial', [11, 12]]] as const) {
    const { calls, http } = transport();
    const api = createImageBatchClient(http);
    await api.run(7, 8, 9, { mode, unit_ids: [...ids] as unknown as number[] });
    assert.deepEqual(calls[0], ['post', BASE + '/run', { mode, unit_ids: [...ids] }]);
  }
});

test('copy-task response keeps only the returned task string', async () => {
  const { calls, http } = transport({ post: async () => ({ data: { task: 'SERVER TASK', extra: 'ignored' } }) });
  const api = createImageBatchClient(http);

  const result = await api.copyTask(7, 8, 9);

  assert.deepEqual(result.task, 'SERVER TASK');
  assert.deepEqual(calls[0], ['post', BASE + '/copy-task', {}]);
});

test('history only changes through a new GET; nothing is appended locally', async () => {
  const workspace = serverRef();
  const { http } = transport({ get: async () => ({ data: workspace }) });
  const api = createImageBatchClient(http);

  assert.deepEqual((await api.loadWorkspace(7, 8, 9)).history, []);

  // 服务端在 run 之后产生的历史；前端没有本地 append 通道（没有 POST history）。
  workspace.history = [
    { id: 1, created_at: '2026-10-14T00:00:00.000000Z', page_labels: ['P1'], status: 'queued', instruction_summary: '柠檬黄暖调' },
  ];

  const history = (await api.loadWorkspace(7, 8, 9)).history;
  assert.equal(history.length, 1);
  assert.equal(history[0].status, 'queued', '第一阶段真实状态只允许 queued');
  assert.deepEqual(history[0].page_labels, ['P1']);
});

test('production modules stay Mock-free; DEV Mock only lives in the DEV preview', () => {
  const production = [
    'resources/js/composables/v2/imageBatch/imageBatchApi.ts',
    'resources/js/composables/v2/imageBatch/useImageBatch.ts',
    'resources/js/composables/v2/imageBatch/imageBatchCore.ts',
    'resources/js/pages/V2/ImageBatch/ImageBatchEditor.vue',
    'resources/js/components/V2/ImageBatch/ImageBatchWorkspaceBody.vue',
    'resources/js/components/V2/ImageBatch/ImageBatchUnitCard.vue',
    'resources/js/components/V2/ImageBatch/ImageBatchAssetSummary.vue',
    'resources/js/components/V2/ImageBatch/ImageBatchBatchHistory.vue',
  ];

  for (const file of production) {
    const source = stripComments(readFileSync(file, 'utf8'));
    assert.doesNotMatch(source, /DevMock/, `${file} 不得引用 DEV Mock`);
    assert.doesNotMatch(source, /SEEDANCE|fakeCharacters|硬编码人物/i, `${file} 不得出现假资产数据`);
  }

  const components = readdirSync('resources/js/components/V2/ImageBatch');
  assert.ok(components.includes('ImageBatchDevPreviewApp.vue'), 'DEV Preview 保留用于独立回归');
});

test('official page calls the composable with exactly projectId / itemId / branchId', () => {
  const source = readFileSync('resources/js/pages/V2/ImageBatch/ImageBatchEditor.vue', 'utf8');

  assert.match(source, /useImageBatch\(props\.projectId,\s*props\.itemId,\s*props\.branchId\)/);
  assert.match(source, /defineProps<\s*\{[^}]*projectId[^}]*itemId[^}]*branchId/s);
  assert.doesNotMatch(source, /localhost|127\.0\.0\.1|http:\/\//, '正式页面不得写死地址');
});

test('asset summary keeps an explicit empty state', () => {
  const source = readFileSync('resources/js/components/V2/ImageBatch/ImageBatchAssetSummary.vue', 'utf8');
  assert.match(source, /assets\.length === 0/);
  assert.match(source, /暂无资产检查数据/);
});

test('unit card selection stays a local prop, never a server field', () => {
  const typeSource = readFileSync('resources/js/types/v2/imageBatch/imageBatch.ts', 'utf8');
  const cardSource = readFileSync('resources/js/components/V2/ImageBatch/ImageBatchUnitCard.vue', 'utf8');

  assert.doesNotMatch(typeSource, /selected\s*:/, 'ImageBatchUnit 不得包含 selected');
  assert.match(cardSource, /selected/);
  assert.doesNotMatch(cardSource, /emit\(\s*['"]update:selected/, '选择不得写回服务端');
});
