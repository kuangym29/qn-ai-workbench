// V2 视频号视频制作 —— DEV Mock client 测试（node:test，无需浏览器）。
// 运行：
//   node --experimental-strip-types --import ./tests/frontend/v2/video/register-loader.mjs \
//     --test tests/frontend/v2/video/videoDevMock.test.ts
//
// 验证 Mock 自身的内存合同（DEV Preview / 测试注入依赖）：
//   GET / PUT（按 source_page_id 更新）/ source-change keep 后 GET 提醒消失。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildMockVideoWorkspace,
  createMockVideoClient,
} from './fixtures.ts';
import type { VideoSaveInput } from '../../../../resources/js/types/v2/video/video.ts';

test('Mock 初始工作负载：6 个单元、总时长 22s、存在来源变化提醒', async () => {
  const m = createMockVideoClient();
  const ws = await m.client.loadWorkspace(1, 1, 1);
  assert.equal(ws.video.units.length, 6);
  assert.equal(ws.total_duration, 22);
  assert.equal(ws.video.source_changed, true);
  assert.ok(ws.video.source_change_summary);
});

test('buildMockVideoWorkspace：系统计算字段与单元一致', () => {
  const ws = buildMockVideoWorkspace();
  assert.equal(ws.timeline.length, 6);
  assert.equal(ws.timeline[5].end_seconds, 22);
});

test('Mock PUT：按 source_page_id 更新单元并重算总时长；调用记录 URL', async () => {
  const m = createMockVideoClient();
  const ws = buildMockVideoWorkspace();
  const input: VideoSaveInput = {
    batch_instruction: ws.video.batch_instruction,
    narration: ws.video.narration,
    units: ws.video.units.map((u) => ({
      source_page_id: u.source_page_id,
      dynamic_instruction: u.dynamic_instruction,
      duration_seconds: u.source_page_id === 101 ? 8 : u.duration_seconds,
      note: u.note,
    })),
  };
  await m.client.save(3, 4, 5, input);
  assert.equal(m.calls[0].method, 'PUT');
  assert.equal(m.calls[0].url, '/api/projects/3/items/4/branches/5/video');

  const refreshed = await m.client.loadWorkspace(3, 4, 5);
  const u1 = refreshed.video.units.find((x) => x.source_page_id === 101)!;
  assert.equal(u1.duration_seconds, 8);
  assert.equal(refreshed.total_duration, 27); // 22 + 5
});

test('Mock keep：POST source-change 后 GET 提醒消失', async () => {
  const m = createMockVideoClient();
  await m.client.loadWorkspace(1, 1, 1);
  await m.client.resolveSourceChange(1, 1, 1, { action: 'keep' });
  assert.equal(m.calls[1].method, 'POST');
  assert.ok(m.calls[1].url.endsWith('/video/source-change'));
  assert.deepEqual(m.calls[1].input, { action: 'keep' });

  const ws = await m.client.loadWorkspace(1, 1, 1);
  assert.equal(ws.video.source_changed, false);
  assert.equal(ws.video.source_change_summary, null);
});

test('每次 createMockVideoClient 独立 store（测试隔离）', async () => {
  const a = createMockVideoClient();
  const b = createMockVideoClient();
  await a.client.resolveSourceChange(1, 1, 1, { action: 'keep' });
  const wa = await a.client.loadWorkspace(1, 1, 1);
  const wb = await b.client.loadWorkspace(1, 1, 1);
  assert.equal(wa.video.source_changed, false);
  assert.equal(wb.video.source_changed, true);
});
