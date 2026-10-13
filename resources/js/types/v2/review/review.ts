// V2「待我审核」轻量审核中心 —— 数据合同类型。
//
// 产品原则：自动聚合入口，不是项目管理系统。
// 正式动作只有：通过 / 退回修改 + 批注。
// 前端只处理 pending / approved / changes_requested；
// 「待我审核」主页面默认只显示 pending，历史审核以后再做。
//
// 正式后端尚未完成：本类型为冻结展示合同；生产 API adapter 独立于 DEV Mock。

export type ReviewStatus = 'pending' | 'approved' | 'changes_requested';

export type ReviewDecisionKind = 'approved' | 'changes_requested';

/** 待审核项（列表行所需的最小展示字段）。 */
export interface ReviewItem {
  id: number;
  content_item_id: number;
  branch_id: number;
  /** 审核状态：主页面默认只显示 pending */
  status: ReviewStatus;
  /** 内容名称 */
  content_title: string;
  /** 渠道：公众号 / 视频号 */
  channel: string;
  /** 内容形式：图片文章 / 图文文章 / 视频制作 */
  format: string;
  /** 审核对象类型（后端定义，如 image_post_copy / article_copy / video_production） */
  review_target_type: string;
  /** 审核对象展示标签：小绿书内容 / 图片批次 / 公众号图文 / 视频制作 */
  review_target_label: string;
  /** 当前版本展示：如 v3 · Revision 3 */
  version_label: string;
  /** 提交时间（ISO 字符串） */
  submitted_at: string;
  /** 列表可读摘要 */
  preview_summary: string | null;
}

/** 审核详情：列表字段 + 预览内容。 */
export interface ReviewDetail extends ReviewItem {
  preview: ReviewPreview | null;
}

/** 预览区域内容（以内容预览为主，不做复杂渲染）。 */
export interface ReviewPreview {
  /** 预览类型：text（单段文本）/ lines（多行文本）/ items（键值列表） */
  kind: 'text' | 'lines' | 'items';
  /** 预览主文本（text 类型） */
  text?: string | null;
  /** 多行文本（lines 类型，如正文段落 / 旁白稿） */
  lines?: string[] | null;
  /** 键值列表（items 类型，如页面/片段清单） */
  items?: { label: string; value: string | null }[] | null;
}

/** 提交审核决策的输入。 */
export interface ReviewDecisionInput {
  decision: ReviewDecisionKind;
  /** 整体批注（可选，退回时通常必填） */
  comment: string | null;
  /** 轻量局部定位预留：如 P4 / 第 03 个视频片段 / 正文 / 封面建议（可选） */
  target_label?: string | null;
}

/** 后端决策结果（append-only，返回后重新拉取列表）。 */
export interface ReviewDecisionResult {
  review_id: number;
  decision: ReviewDecisionKind;
  comment: string | null;
  target_label: string | null;
  decided_at: string;
}

// ---------------------------------------------------------------------------
// 开发预览 MOCK 合同（DEV ONLY，明确不进入生产）
// ---------------------------------------------------------------------------

/** 开发预览的 providers；正式页面使用后端版本。 */
export interface ReviewImportProviders {
  list: () => Promise<ReviewItem[]>;
  detail: (id: number) => Promise<ReviewDetail>;
  decide: (id: number, input: ReviewDecisionInput) => Promise<ReviewDecisionResult>;
}
