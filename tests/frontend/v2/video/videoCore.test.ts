// V2-P3-C03 视频号视频制作核心纯函数测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/video/videoCore.test.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  computeTotalDuration,
  formatDuration,
  buildTimeline,
  sortUnitsByOrder,
  buildSaveInput,
  normalizeDurationSeconds,
} from '../../../../resources/js/composables/v2/video/videoCore.ts';
import type { VideoProduction } from '../../../../resources/js/types/v2/video/video.ts';

function unit(id: number, order: number, duration_seconds: number, source_label: string | null = null): VideoProduction['units'][number] {
  return { id, order, source_page_id: id, source_label, dynamic_instruction: '动', duration_seconds, note: null };
}

test('computeTotalDuration：正常求和 / 非法值忽略 / 空列表为 0', () => {
  assert.equal(computeTotalDuration([unit(1, 1, 3), unit(2, 2, 4), unit(3, 3, 5)]), 12);
  assert.equal(computeTotalDuration([unit(1, 1, 0), unit(2, 2, -1), unit(3, 3, 2)]), 2);
  assert.equal(computeTotalDuration([]), 0);
});

test('formatDuration：秒 → Xs，负数收敛为 0s', () => {
  assert.equal(formatDuration(24), '24s');
  assert.equal(formatDuration(0), '0s');
  assert.equal(formatDuration(-3), '0s');
  assert.equal(formatDuration(2.4), '2s');
});

test('buildTimeline：按 order 累积起止，时长未设置给出提示', () => {
  const tl = buildTimeline([unit(2, 2, 4, 'P2'), unit(1, 1, 3, '封面'), unit(3, 3, 0, 'P3')]);
  assert.equal(tl.length, 3);
  assert.equal(tl[0].order, 1);
  assert.equal(tl[0].source_label, '封面');
  assert.equal(tl[0].start_seconds, 0);
  assert.equal(tl[0].end_seconds, 3);
  assert.equal(tl[1].start_seconds, 3);
  assert.equal(tl[1].end_seconds, 7);
  assert.equal(tl[2].narration_note, '时长未设置');
});

test('sortUnitsByOrder：稳定排序，无 order 排最后', () => {
  const sorted = sortUnitsByOrder([unit(2, 2, 4), unit(1, 1, 3), unit(3, 99, 1)]);
  assert.deepEqual(sorted.map((u) => u.order), [1, 2, 99]);
  const noOrder = sortUnitsByOrder([unit(1, 0, 1), unit(2, 5, 1)]);
  assert.deepEqual(noOrder.map((u) => u.order), [0, 5]);
});

test('buildSaveInput：只收敛用户可编辑字段，不提交系统字段', () => {
  const video: VideoProduction = {
    id: 1,
    project_id: 1,
    content_item_id: 1,
    title: '标题',
    source_label: '来源',
    batch_instruction: '整批说明',
    narration: '旁白',
    units: [unit(7, 1, 3, '封面')],
    source_changed: false,
    source_change_summary: null,
  };
  const input = buildSaveInput(video);
  assert.equal(input.batch_instruction, '整批说明');
  assert.equal(input.narration, '旁白');
  assert.equal(input.units.length, 1);
  assert.equal(input.units[0].id, 7);
  assert.ok(!('source_page_id' in input.units[0]));
  assert.ok(!('order' in input.units[0]));
  assert.ok(!('source_label' in input.units[0]));
});

test('normalizeDurationSeconds：非负整数保留，非法回退原值', () => {
  assert.equal(normalizeDurationSeconds('4', 3), 4);
  assert.equal(normalizeDurationSeconds(-2, 3), 3);
  assert.equal(normalizeDurationSeconds('abc', 5), 5);
  assert.equal(normalizeDurationSeconds(2.7, 5), 5);
});
