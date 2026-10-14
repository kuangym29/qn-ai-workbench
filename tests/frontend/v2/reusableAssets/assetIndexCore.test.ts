// V2-P3-C06 assetIndexCore 纯函数测试（node:test，仅依赖 type-only 导入）。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  countByStatus,
  filterItems,
  formatLastUsed,
  getItemsByType,
  groupByType,
  statusLabel,
} from '../../../../resources/js/composables/v2/reusableAssets/assetIndexCore.ts';
import type {
  ReusableAssetItem,
  ReusableAssetWorkspace,
} from '../../../../resources/js/types/v2/reusableAssets/reusableAssets.ts';

function item(id: number, type: ReusableAssetItem['type'], status: ReusableAssetItem['status']): ReusableAssetItem {
  return { id, type, name: `n${id}`, status, thumbnail_url: null, description: null, last_used_at: null };
}

const ws: ReusableAssetWorkspace = {
  characters: [item(1, 'character', 'existing'), item(2, 'character', 'missing'), item(3, 'character', 'existing')],
  scenes: [item(4, 'scene', 'existing'), item(5, 'scene', 'missing')],
};

test('getItemsByType 按 Tab 取分组', () => {
  assert.equal(getItemsByType(ws, 'character').length, 3);
  assert.equal(getItemsByType(ws, 'scene').length, 2);
  assert.deepEqual(getItemsByType(ws, 'character').map((i) => i.id), [1, 2, 3]);
});

test('filterItems 全部 / 已有 / 缺失', () => {
  const chars = getItemsByType(ws, 'character');
  assert.equal(filterItems(chars, 'all').length, 3);
  assert.equal(filterItems(chars, 'existing').length, 2);
  assert.equal(filterItems(chars, 'missing').length, 1);
  assert.ok(filterItems(chars, 'missing').every((i) => i.status === 'missing'));
});

test('countByStatus 计数', () => {
  const chars = getItemsByType(ws, 'character');
  assert.deepEqual(countByStatus(chars), { existing: 2, missing: 1, total: 3 });
  assert.deepEqual(countByStatus([]), { existing: 0, missing: 0, total: 0 });
});

test('statusLabel 中文展示', () => {
  assert.equal(statusLabel('existing'), '已有');
  assert.equal(statusLabel('missing'), '缺失');
});

test('formatLastUsed null 与 ISO 日期', () => {
  assert.equal(formatLastUsed(null), '未使用');
  assert.equal(formatLastUsed(''), '未使用');
  assert.equal(formatLastUsed('2026-09-28T10:12:00+08:00'), '2026-09-28');
  assert.equal(formatLastUsed('2026-09-28'), '2026-09-28');
  // 非日期格式原样展示（防御）
  assert.equal(formatLastUsed('不久前'), '不久前');
});

test('groupByType 分组并忽略无法识别 type', () => {
  const mixed = [
    item(1, 'character', 'existing'),
    item(2, 'scene', 'missing'),
    { ...item(3, 'character', 'existing'), type: 'extra' as unknown as ReusableAssetItem['type'] },
  ];
  const grouped = groupByType(mixed);
  assert.equal(grouped.character.length, 1);
  assert.equal(grouped.scene.length, 1);
});
