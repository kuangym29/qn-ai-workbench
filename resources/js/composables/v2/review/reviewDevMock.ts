// V2「待我审核」轻量审核中心 —— DEV 预览 Mock 提供者（DEV ONLY，独立于正式生产模块）。
//
// 正式生产 composable（reviewCore / reviewApi / useReviewCenter）绝不引用本文件。
// 本文件只被：
//   - ReviewDevPreviewApp.vue（浏览器开发预览）
//   - reviewDevMock.test.ts（DEV 展示测试）
// 引用。正式路径只能：列表/详情/决策全部由后端 API 提供。
import type {
  ReviewDecisionInput,
  ReviewDecisionResult,
  ReviewDetail,
  ReviewItem,
} from '../../../types/v2/review/review';

interface MockState {
  items: ReviewItem[];
}

const now = new Date('2026-10-13T09:00:00+08:00').toISOString();

function iso(daysAgo: number, hour: number): string {
  const d = new Date('2026-10-13T09:00:00+08:00');
  d.setDate(d.getDate() - daysAgo);
  d.setHours(hour, 30, 0, 0);
  return d.toISOString();
}

function buildMockItems(): ReviewItem[] {
  return [
    {
      id: 101,
      content_item_id: 1,
      branch_id: 1,
      status: 'pending',
      content_title: '孩子说“我自己来”时，先别急着帮',
      channel: 'wechat_official',
      format: 'image_post',
      review_target_type: 'image_post_copy',
      review_target_label: '小绿书内容',
      version_label: 'v3 · Revision 3',
      submitted_at: iso(0, 8),
      preview_summary: '共 10 页：封面 / 8 页正文 / 收尾。核心表达：等待是给孩子练习的空间。',
    },
    {
      id: 102,
      content_item_id: 1,
      branch_id: 1,
      status: 'pending',
      content_title: '孩子说“我自己来”时，先别急着帮',
      channel: 'wechat_official',
      format: 'image_post',
      review_target_type: 'image_batch',
      review_target_label: '图片批次',
      version_label: '批次 2',
      submitted_at: iso(0, 7),
      preview_summary: '10 张图稿：妈妈门口等待 / 孩子系鞋带 / 抬头说我系好了。',
    },
    {
      id: 103,
      content_item_id: 2,
      branch_id: 1,
      status: 'pending',
      content_title: '积木倒了，孩子哭了',
      channel: 'wechat_official',
      format: 'article',
      review_target_type: 'article_copy',
      review_target_label: '公众号图文',
      version_label: 'v1 · 初稿',
      submitted_at: iso(1, 16),
      preview_summary: '标题 / 摘要 / 正文 12 段 / 配图建议 2 条 / 封面建议 1 条。',
    },
    {
      id: 104,
      content_item_id: 3,
      branch_id: 1,
      status: 'pending',
      content_title: '弟弟想玩车，姐姐还没玩完',
      channel: 'wechat_channels',
      format: 'video',
      review_target_type: 'video_production',
      review_target_label: '视频制作',
      version_label: 'v2',
      submitted_at: iso(2, 11),
      preview_summary: '整批说明 1 段 / 完整旁白稿 / 9 个视频片段，预计总时长 24s。',
    },
    {
      id: 105,
      content_item_id: 4,
      branch_id: 1,
      status: 'pending',
      content_title: '一只纸箱，开了家水果店',
      channel: 'wechat_official',
      format: 'image_post',
      review_target_type: 'image_post_copy',
      review_target_label: '小绿书内容',
      version_label: 'v1 · 初稿',
      submitted_at: iso(3, 9),
      preview_summary: '共 8 页：封面 / 6 页正文 / 收尾。',
    },
    {
      id: 106,
      content_item_id: 5,
      branch_id: 1,
      status: 'pending',
      content_title: '你肯听，情绪就有了出口',
      channel: 'wechat_official',
      format: 'article',
      review_target_type: 'article_copy',
      review_target_label: '公众号图文',
      version_label: 'v2',
      submitted_at: iso(4, 15),
      preview_summary: '标题 / 摘要 / 正文 8 段。收尾句：你肯听，情绪就有了出口。',
    },
    {
      id: 107,
      content_item_id: 6,
      branch_id: 1,
      status: 'pending',
      content_title: '轮流不是催姐姐让',
      channel: 'wechat_channels',
      format: 'video',
      review_target_type: 'video_production',
      review_target_label: '视频制作',
      version_label: 'v1',
      submitted_at: iso(5, 10),
      preview_summary: '整批说明 / 旁白稿 / 6 个视频片段。',
    },
  ];
}

function buildMockDetail(item: ReviewItem): ReviewDetail {
  let preview: ReviewDetail['preview'] = null;
  if (item.format === 'image_post') {
    preview = {
      kind: 'items',
      items: [
        { label: '页面', value: item.content_title },
        { label: '核心表达', value: '等待是给孩子练习的空间。' },
        { label: '页数', value: '10 页（含封面与收尾）' },
        { label: '收尾句', value: '你在身边，“我自己来”更有底气。' },
      ],
    };
  } else if (item.format === 'article') {
    preview = {
      kind: 'lines',
      lines: [
        '【标题】' + item.content_title,
        '【摘要】' + (item.preview_summary ?? ''),
        '第一段：出门前，孩子总有自己的节奏。',
        '第二段：他把鞋带绕了一圈又一圈，小手笨拙却很认真。',
        '……（正文共 8–12 段）',
      ],
    };
  } else if (item.format === 'video') {
    preview = {
      kind: 'items',
      items: [
        { label: '整批说明', value: '整体节奏轻快，人物动作自然，转场以柔和淡入为主。' },
        { label: '片段', value: '01 · 来源封面（3s）→ 02 · 来源 P2（4s）→ …' },
        { label: '预计总时长', value: '24s' },
      ],
    };
  } else {
    preview = { kind: 'text', text: item.preview_summary };
  }
  return { ...item, preview };
}

/** 当前 mock 内存状态（DEV ONLY）。 */
export const mockReviewStore: MockState = {
  items: buildMockItems(),
};

/** DEV ONLY MOCK —— 拉取 pending 列表。 */
export async function buildMockReviewList(): Promise<ReviewItem[]> {
  return mockReviewStore.items.filter((i) => i.status === 'pending');
}

/** DEV ONLY MOCK —— 拉取详情；id 不存在时返回 null（由调用方兜底为 404 表现）。 */
export async function buildMockReviewDetail(id: number): Promise<ReviewDetail | null> {
  const item = mockReviewStore.items.find((i) => i.id === id);
  return item ? buildMockDetail(item) : null;
}

/** DEV ONLY MOCK —— 提交决策并更新内存状态。 */
export async function applyMockDecision(
  id: number,
  input: ReviewDecisionInput,
): Promise<ReviewDecisionResult> {
  const item = mockReviewStore.items.find((i) => i.id === id);
  if (!item) {
    throw new Error('审核项不存在（DEV MOCK 404）');
  }
  item.status = input.decision === 'approved' ? 'approved' : 'changes_requested';
  const result: ReviewDecisionResult = {
    review_id: id,
    decision: input.decision,
    comment: input.comment ?? null,
    target_label: input.target_label ?? null,
    decided_at: now,
  };
  return result;
}
