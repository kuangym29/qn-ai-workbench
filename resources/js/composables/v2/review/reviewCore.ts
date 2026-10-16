// V2「待我审核」轻量审核中心 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责边界：
//   正式审核决策由后端完成，本模块【不实现】审核业务逻辑。
//   这里只提供：
//     1. pendingOnly()            过滤 pending 列表。
//     2. 展示标签映射（渠道 / 内容形式 / 状态 / 审核对象）。
//     3. truncateSummary()        列表摘要截断。
//     4. detectTargetLabel()      轻量局部定位（target_label）预留提取。
//   DEV 预览 Mock（buildMockReviewItems 等）位于 reviewDevMock.ts（DEV ONLY），
//   正式生产模块不引用。
import type {
  ReviewChannel,
  ReviewFormat,
  ReviewItem,
  ReviewStatus,
} from '../../../types/v2/review/review';

export const REVIEW_STATUS_LABELS: Record<ReviewStatus, string> = {
  pending: '待审核',
  approved: '已通过',
  changes_requested: '已退回修改',
};

/** 渠道中文标签（正式枚举：wechat_official / wechat_channels / xiaohongshu）。 */
export const REVIEW_CHANNEL_LABELS: Record<ReviewChannel, string> = {
  wechat_official: '公众号',
  wechat_channels: '视频号',
  xiaohongshu: '小红书',
};

/** 内容形式中文标签（正式枚举：image_post / article / video）。 */
export const REVIEW_FORMAT_LABELS: Record<ReviewFormat, string> = {
  image_post: '图片文章',
  article: '图文文章',
  video: '视频制作',
};

/** 审核对象中文标签（未知对象原样返回）。 */
export const REVIEW_TARGET_LABELS: Record<string, string> = {
  image_post_copy: '图片文章',
  article_copy: '公众号图文',
  video_production: '视频制作',
  image_batch: '图片批次',
};

export function channelLabel(channel: ReviewChannel): string {
  return REVIEW_CHANNEL_LABELS[channel] ?? channel;
}

export function formatLabel(format: ReviewFormat): string {
  return REVIEW_FORMAT_LABELS[format] ?? format;
}

export function targetLabel(review_target_type: string): string {
  return REVIEW_TARGET_LABELS[review_target_type] ?? review_target_type;
}

/** 只看 pending：主页面默认只显示待审核。 */
export function pendingOnly(items: ReviewItem[] | null | undefined): ReviewItem[] {
  if (!items) return [];
  return items.filter((i) => i.status === 'pending');
}

export function isPending(item: ReviewItem): boolean {
  return item.status === 'pending';
}

/** 列表摘要截断（按字符，保留尾部省略号）。 */
export function truncateSummary(text: string | null, max = 80): string {
  if (!text) return '';
  const t = text.trim();
  if (t.length <= max) return t;
  return `${t.slice(0, max)}…`;
}

/**
 * 轻量局部定位（target_label）预留提取 —— 不是复杂标注系统。
 *
 * 从批注文本中尽力识别一个局部定位标签：
 *   - 「P4」「p3」→ "P4"
 *   - 「第 03 个视频片段」「第3个视频片段」→ "第 03 个视频片段"
 *   - 「正文」「标题」「摘要」「封面建议」→ 原词
 * 识别不到返回 null（整体批注）。
 */
export function detectTargetLabel(comment: string | null | undefined): string | null {
  if (!comment) return null;
  const t = comment.trim();
  if (!t) return null;

  const page = t.match(/P\s*(\d{1,2})/i);
  if (page) return `P${Number(page[1])}`;

  const segment = t.match(/第\s*(\d{1,2})\s*个\s*视频片段/);
  if (segment) return `第 ${String(Number(segment[1])).padStart(2, '0')} 个视频片段`;

  const field = t.match(/(正文|标题|摘要|封面建议)/);
  if (field) return field[1];

  return null;
}
