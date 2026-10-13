// V2-P3-C03-R1 视频制作状态机测试（node:test + vue reactivity，无需浏览器）。
// 运行：
//   node --experimental-strip-types --import ./tests/frontend/v2/video/register-loader.mjs \
//     --test tests/frontend/v2/video/useVideoEditor.test.ts
//
// 覆盖 R1 合同重点：
//   - branch-aware URL（projectId / itemId / branchId 三参数）；GET / PUT / source-change；
//   - PUT 白名单（units 以 source_page_id 定位，不提交系统字段）；
//   - 保存成功后重新 GET；PUT 失败不刷新、保留本地编辑；
//   - source-change keep 成功后重新 GET；失败不清本地 source_changed；
//   - duration 合同（非法回退）；timeline / total_duration 只读不提交；
//   - 生产模块 0 引用 DEV Mock；重复提交 guard。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { useVideoEditor } from '../../../../resources/js/composables/v2/video/useVideoEditor.ts';
import { createVideoClient, type VideoTransport } from '../../../../resources/js/composables/v2/video/videoApi.ts';
import { buildMockVideoWorkspace } from '../../../../resources/js/composables/v2/video/videoDevMock.ts';
import type {
  VideoEditorWorkspace,
  VideoSaveInput,
  VideoSourceChangeInput,
} from '../../../../resources/js/types/v2/video/video.ts';

interface Call {
  method: 'GET' | 'PUT' | 'POST';
  url: string;
  input?: unknown;
}

function makeHarness() {
  let ws: VideoEditorWorkspace = structuredClone(buildMockVideoWorkspace());
  const calls: Call[] = [];
  const flags = { failPut: false, failPost: false, blockPut: false };
  let releasePut: (() => void) | null = null;

  const transport: VideoTransport = {
    async get(url: string) {
      calls.push({ method: 'GET', url });
      return { data: { data: structuredClone(ws) } };
    },
    async put(url: string, input: unknown) {
      calls.push({ method: 'PUT', url, input });
      if (flags.failPut) throw new Error('PUT 失败');
      if (flags.blockPut) {
        await new Promise<void>((resolve) => {
          releasePut = resolve;
        });
      }
      const body = input as VideoSaveInput;
      ws.video.batch_instruction = body.batch_instruction;
      ws.video.narration = body.narration;
      ws.video.units = ws.video.units.map((u) => {
        const hit = body.units.find((x) => x.source_page_id === u.source_page_id);
        return hit ? { ...u, ...hit } : u;
      });
      return { data: { data: { ok: true } } };
    },
    async post(url: string, input?: unknown) {
      calls.push({ method: 'POST', url, input });
      if (flags.failPost) throw new Error('POST 失败');
      if (url.endsWith('/source-change') && (input as VideoSourceChangeInput).action === 'keep') {
        ws.video.source_changed = false;
        ws.video.source_change_summary = null;
      }
      return { data: { data: { ok: true } } };
    },
  };

  return {
    client: createVideoClient(transport),
    calls,
    flags,
    releasePut: () => releasePut?.(),
  };
}

test('GET 使用 branch-aware URL：project / item / branch 三参数齐全', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  const get = h.calls.find((c) => c.method === 'GET');
  assert.ok(get);
  assert.equal(get!.url, '/api/projects/7/items/8/branches/9/video');
});

test('PUT 使用 branch-aware URL 且为白名单 VideoSaveInput（units 以 source_page_id 定位）', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  const ok = await editor.save();
  assert.equal(ok, true);

  const put = h.calls.find((c) => c.method === 'PUT');
  assert.ok(put);
  assert.equal(put!.url, '/api/projects/7/items/8/branches/9/video');

  const input = put!.input as VideoSaveInput;
  assert.deepEqual(Object.keys(input).sort(), ['batch_instruction', 'narration', 'units']);
  assert.equal(input.units.length, 6);
  const u0 = input.units[0];
  assert.deepEqual(Object.keys(u0).sort(), ['duration_seconds', 'dynamic_instruction', 'note', 'source_page_id']);
  assert.equal(u0.source_page_id, 101);
  assert.equal(u0.duration_seconds, 3);
  assert.ok(!('id' in u0));
  assert.ok(!('order' in u0));
  assert.ok(!('source_label' in u0));
  // 系统 / 继承字段不提交
  for (const forbidden of ['title', 'source_label', 'source_changed', 'source_change_summary', 'total_duration', 'timeline']) {
    assert.ok(!(forbidden in input), `保存输入不应包含 ${forbidden}`);
  }
});

test('保存成功后重新 GET（PUT 之后紧跟一次 GET，以服务端数据为准）', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  h.calls.length = 0;
  await editor.save();
  const methods = h.calls.map((c) => c.method);
  assert.deepEqual(methods, ['PUT', 'GET']);
});

test('PUT 失败：不重新 GET、不提示成功，本地编辑保留', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  editor.updateNarration('尚未保存的旁白修改');
  h.flags.failPut = true;
  const ok = await editor.save();
  assert.equal(ok, false);
  const putIndex = h.calls.findIndex((c) => c.method === 'PUT');
  assert.ok(putIndex >= 0);
  assert.ok(!h.calls.slice(putIndex + 1).some((c) => c.method === 'GET'), 'PUT 失败后不应重新 GET');
  assert.equal(editor.video.value?.narration, '尚未保存的旁白修改');
});

test('source-change keep：POST branch-aware URL，body={action:keep}，成功后重新 GET', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  assert.equal(editor.video.value?.source_changed, true);
  h.calls.length = 0;

  const ok = await editor.keepSourceChanges();
  assert.equal(ok, true);
  const post = h.calls.find((c) => c.method === 'POST');
  assert.ok(post);
  assert.equal(post!.url, '/api/projects/7/items/8/branches/9/video/source-change');
  assert.deepEqual(post!.input, { action: 'keep' });
  // 成功后重新 GET
  const postIndex = h.calls.findIndex((c) => c.method === 'POST');
  assert.ok(h.calls.slice(postIndex + 1).some((c) => c.method === 'GET'));
  // 服务端正式数据确认提醒已消失（非本地伪造）
  assert.equal(editor.video.value?.source_changed, false);
  assert.equal(editor.video.value?.source_change_summary, null);
});

test('source-change POST 失败：不重新 GET，本地 source_changed 提醒保留', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  h.flags.failPost = true;
  const ok = await editor.keepSourceChanges();
  assert.equal(ok, false);
  const postIndex = h.calls.findIndex((c) => c.method === 'POST');
  assert.ok(!h.calls.slice(postIndex + 1).some((c) => c.method === 'GET'), 'POST 失败后不应重新 GET');
  assert.equal(editor.video.value?.source_changed, true, '失败时不得本地清掉来源提醒');
});

test('duration 合同：非法输入回退原值；本地预计总时长仅作只读展示', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  // 初始总时长 22s（3+4+4+3+3+5）
  assert.equal(editor.totalDuration.value, 22);

  const firstUnit = editor.video.value!.units[0];
  editor.updateUnit(firstUnit.id, { duration_seconds: 6 });
  assert.equal(editor.totalDuration.value, 25);
  // 非法输入（小数 / 负数 / 非数字）回退该单元原值
  editor.updateUnit(firstUnit.id, { duration_seconds: 2.4 as unknown as number });
  assert.equal(editor.totalDuration.value, 25);
  // 时间轴为只读系统计算（长度与单元一致）
  assert.equal(editor.timeline.value.length, 6);
});

test('重复提交 guard：保存进行中再次 save / keep 不产生第二次请求', async () => {
  const h = makeHarness();
  h.flags.blockPut = true;
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  h.calls.length = 0;

  const first = editor.save();
  const secondSave = await editor.save();
  const keepDuringSave = await editor.keepSourceChanges();
  assert.equal(secondSave, false);
  assert.equal(keepDuringSave, false);
  assert.equal(h.calls.filter((c) => c.method === 'PUT').length, 1);
  assert.equal(h.calls.filter((c) => c.method === 'POST').length, 0);

  h.flags.blockPut = false;
  h.releasePut();
  const firstResult = await first;
  assert.equal(firstResult, true);
});

test('无来源变化时 keepSourceChanges 直接成功且不发起请求', async () => {
  const h = makeHarness();
  const editor = useVideoEditor(7, 8, 9, h.client);
  await editor.load();
  await editor.keepSourceChanges();
  h.calls.length = 0;
  const ok = await editor.keepSourceChanges();
  assert.equal(ok, true);
  assert.equal(h.calls.length, 0);
});

test('生产模块 0 引用 DEV Mock（videoApi / useVideoEditor 源码不含 DevMock）', () => {
  const here = dirname(fileURLToPath(import.meta.url));
  const compDir = join(here, '..', '..', '..', '..', 'resources', 'js', 'composables', 'v2', 'video');
  const apiSrc = readFileSync(join(compDir, 'videoApi.ts'), 'utf8');
  const editorSrc = readFileSync(join(compDir, 'useVideoEditor.ts'), 'utf8');
  assert.ok(!/videoDevMock|DevMock/i.test(apiSrc), 'videoApi 不得引用 DEV Mock');
  assert.ok(!/videoDevMock|DevMock/i.test(editorSrc), 'useVideoEditor 不得引用 DEV Mock');
});
