// P5-I02 复用资产「轻量资产管理」纯函数测试（node:test）。
//
// 覆盖弹层表单归一与载荷构造：
//   - 名称 trim / 必填判定
//   - 空字符串 → null（显式清空预览图 / 说明）
//   - 创建载荷带 type、且绝不带 id / project_id / last_used_at
//   - 编辑载荷只含 status / thumbnail_url / description（绝不带 name / type）
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildAssetCreatePayload,
  buildAssetUpdatePayload,
  isAssetNameValid,
  normalizeOptionalText,
} from '../../../../resources/js/composables/v2/reusableAssets/assetIndexCore.ts';
import type {
  AssetEditorForm,
} from '../../../../resources/js/types/v2/reusableAssets/reusableAssets.ts';

const form = (over: Partial<AssetEditorForm> = {}): AssetEditorForm => ({
  name: '妈妈',
  status: 'missing',
  thumbnail_url: '',
  description: '',
  ...over,
});

test('normalizeOptionalText：空白 → null（显式清空），保留裁剪后的内容', () => {
  assert.equal(normalizeOptionalText(''), null);
  assert.equal(normalizeOptionalText('   '), null);
  assert.equal(normalizeOptionalText(null), null);
  assert.equal(normalizeOptionalText(undefined), null);
  assert.equal(normalizeOptionalText('  /storage/a.png  '), '/storage/a.png');
});

test('isAssetNameValid：空 / 全空白视为无效', () => {
  assert.equal(isAssetNameValid(''), false);
  assert.equal(isAssetNameValid('   '), false);
  assert.equal(isAssetNameValid(' 妈妈 '), true);
});

test('buildAssetCreatePayload：带 type，名称 trim，空字段归 null', () => {
  const payload = buildAssetCreatePayload('character', form({ name: '  妈妈  ' }));
  assert.deepEqual(payload, {
    type: 'character',
    name: '妈妈',
    status: 'missing',
    thumbnail_url: null,
    description: null,
  });
});

test('buildAssetCreatePayload：绝不携带 id / project_id / last_used_at 等服务器字段', () => {
  const payload = buildAssetCreatePayload('scene', form()) as unknown as Record<string, unknown>;
  for (const forbidden of ['id', 'project_id', 'last_used_at', 'created_at', 'updated_at']) {
    assert.equal(forbidden in payload, false, `创建载荷不得包含 ${forbidden}`);
  }
  assert.deepEqual(Object.keys(payload).sort(), ['description', 'name', 'status', 'thumbnail_url', 'type']);
});

test('buildAssetUpdatePayload：只含三键，绝不携带 name / type', () => {
  const payload = buildAssetUpdatePayload(form({ thumbnail_url: 'https://cdn.test/a.png', description: '说明' })) as unknown as Record<string, unknown>;
  assert.deepEqual(Object.keys(payload).sort(), ['description', 'status', 'thumbnail_url']);
  assert.equal('name' in payload, false);
  assert.equal('type' in payload, false);
  assert.equal(payload.thumbnail_url, 'https://cdn.test/a.png');
  assert.equal(payload.description, '说明');
});

test('buildAssetUpdatePayload：清空（空字符串）下发显式 null', () => {
  const payload = buildAssetUpdatePayload(form({ thumbnail_url: '', description: '' }));
  assert.equal(payload.thumbnail_url, null, '清空预览图必须是显式 null（不是缺省键）');
  assert.equal(payload.description, null, '清空说明必须是显式 null（不是缺省键）');
  assert.ok('thumbnail_url' in payload && 'description' in payload);
});

test('buildAssetUpdatePayload：missing → existing 状态切换如实下发', () => {
  const payload = buildAssetUpdatePayload(form({ status: 'existing' }));
  assert.equal(payload.status, 'existing');
});
