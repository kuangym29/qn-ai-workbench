// V2-P3-C04 审核中心核心纯函数测试（node:test，无需 Vue / 浏览器）。
// 运行：node --experimental-strip-types --test tests/frontend/v2/review/reviewCore.test.ts
// 说明：DEV Mock 已隔离到 reviewDevMock.ts，其测试位于 reviewDevMock.test.ts；
// 本文件只测正式生产核心纯函数（过滤 / 标签 / 摘要 / 轻量局部定位预留）。
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  channelLabel,
  detectTargetLabel,
  formatLabel,
  isPending,
  pendingOnly,
  targetLabel,
  truncateSummary,
} from '../../../../resources/js/composables/v2/review/reviewCore.ts';
import type { ReviewItem } from '../../../../resources/js/types/v2/review/review.ts';

function makeItem(overrides: Partial<ReviewItem> = {}): ReviewItem {
  return {
    id: 1,
    content_item_id: 1,
    branch_id: 1,
    status: 'pending',
    content_title: '示例内容',
    channel: 'wechat_official',
    format: 'image_post',
    review_target_type: 'image_post_copy',
    review_target_label: '小绿书内容',
    version_label: 'v1 · 初稿',
    submitted_at: '2026-10-13T08:00:00+08:00',
    preview_summary: '共 10 页。',
    ...overrides,
  };
}

test('pendingOnly：只保留 pending，空输入返回空数组', () => {
  const items = [
    makeItem({ id: 1, status: 'pending' }),
    makeItem({ id: 2, status: 'approved' }),
    makeItem({ id: 3, status: 'changes_requested' }),
    makeItem({ id: 4, status: 'pending' }),
  ];
  const p = pendingOnly(items);
  assert.equal(p.length, 2);
  assert.equal(p[0].id, 1);
  assert.equal(p[1].id, 4);
  assert.equal(pendingOnly(null).length, 0);
  assert.equal(pendingOnly(undefined).length, 0);
  assert.equal(pendingOnly([]).length, 0);
});

test('isPending 判定', () => {
  assert.equal(isPending(makeItem({ status: 'pending' })), true);
  assert.equal(isPending(makeItem({ status: 'approved' })), false);
  assert.equal(isPending(makeItem({ status: 'changes_requested' })), false);
});

test('channelLabel / formatLabel / targetLabel 映射与正式枚举', () => {
  assert.equal(channelLabel('wechat_official'), '公众号');
  assert.equal(channelLabel('wechat_channels'), '视频号');
  assert.equal(channelLabel('xiaohongshu'), '小红书');
  assert.equal(formatLabel('image_post'), '图片文章');
  assert.equal(formatLabel('article'), '图文文章');
  assert.equal(formatLabel('video'), '视频制作');
  assert.equal(targetLabel('image_post_copy'), '小绿书内容');
  assert.equal(targetLabel('image_batch'), '图片批次');
  assert.equal(targetLabel('article_copy'), '公众号图文');
  assert.equal(targetLabel('video_production'), '视频制作');
  assert.equal(targetLabel('unknown_target'), 'unknown_target');
});

test('truncateSummary：空值 / 短文本 / 长文本截断', () => {
  assert.equal(truncateSummary(null), '');
  assert.equal(truncateSummary(''), '');
  assert.equal(truncateSummary('   '), '');
  assert.equal(truncateSummary('短文本'), '短文本');
  const long = '共 10 页：封面 / 8 页正文 / 收尾。核心表达：等待是给孩子练习的空间。更多细节……';
  const t = truncateSummary(long, 20);
  assert.equal(t.length, 21); // 20 字符 + 省略号
  assert.ok(t.endsWith('…'));
  assert.ok(!t.includes('更多细节'));
});

test('detectTargetLabel：P 页码 / 视频片段 / 字段词 / 无定位', () => {
  assert.equal(detectTargetLabel('P4 的画面证据请补充'), 'P4');
  assert.equal(detectTargetLabel('请修改 p3 的文案'), 'P3');
  assert.equal(detectTargetLabel('第 03 个视频片段节奏太慢'), '第 03 个视频片段');
  assert.equal(detectTargetLabel('第3个视频片段需要重做'), '第 03 个视频片段');
  assert.equal(detectTargetLabel('正文第二段表述不准确'), '正文');
  assert.equal(detectTargetLabel('封面建议再给一张备选'), '封面建议');
  assert.equal(detectTargetLabel('整体节奏没问题，稍微调整语气即可'), null);
  assert.equal(detectTargetLabel(null), null);
  assert.equal(detectTargetLabel('   '), null);
});
