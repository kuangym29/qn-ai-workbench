// V2 公众号图片文章（小绿书）内容编辑器 —— 数据合同类型
//
// 字段名以任务书（V2-P2-C01）为准，不在前端擅自扩展业务字段。
// 注意与 V2 foundation api/v2.ts 的差异：该处 ContentItem 使用 core_message，
// 本模块按任务书使用 core_expression；映射差异在最终报告中列明，交由
// Work 主程序集成时统一。
import type { PageType } from '../../api/types';

// ---------------------------------------------------------------------------
// 篇目级（Content Item）
// ---------------------------------------------------------------------------

/** 图片文章篇目编辑视图。project_id / topic_id 等归属字段由服务端持有，前端只读。 */
export interface ImagePostContentItem {
  id: number;
  project_id: number;
  title: string;
  /** 核心表达（任务书字段；后端若为 core_message 由 adapter 归一化） */
  core_expression: string | null;
  /** 整篇故事线 */
  story_line: string | null;
  /** 来源展示：栏目名称（来自 ContentColumn） */
  column_name?: string | null;
  /** 来源展示：栏目 slug（用于溯源链接） */
  column_slug?: string | null;
}

// ---------------------------------------------------------------------------
// 页面级（每页内容卡固定 8 项）
// ---------------------------------------------------------------------------

/** 图片文章页面字段（固定 8 项，含页码与页面类型）。null 表示字段无内容。 */
export interface ImagePostPage {
  /** 页码，决定排序与展示顺序 */
  page_no: number;
  page_type: PageType;
  /** 叙事职责 */
  narrative_role: string | null;
  /** 本页发生的新事实 */
  new_fact: string | null;
  /** 最终文字 */
  final_copy: string | null;
  /** 画面证据 */
  visual_evidence: string | null;
  /** 前后页承接 */
  continuity: string | null;
  /** 制作备注（弱化展示） */
  production_note: string | null;
}

/** 可比较 / 可更新的业务字段（不含 page_no 与 page_type 两个标识字段）。 */
export const IMAGE_POST_PAGE_FIELDS = [
  'narrative_role',
  'new_fact',
  'final_copy',
  'visual_evidence',
  'continuity',
  'production_note',
] as const;

export type ImagePostPageField = (typeof IMAGE_POST_PAGE_FIELDS)[number];

/** 字段中文标签（编辑表单 / Diff 预览共用）。 */
export const IMAGE_POST_FIELD_LABELS: Record<ImagePostPageField, string> = {
  narrative_role: '叙事职责',
  new_fact: '本页发生的新事实',
  final_copy: '最终文字',
  visual_evidence: '画面证据',
  continuity: '前后页承接',
  production_note: '制作备注',
};

/** 常用信息优先展示顺序：最终文字 / 画面证据 / 新事实在前，备注最后。 */
export const IMAGE_POST_FIELD_ORDER: ImagePostPageField[] = [
  'final_copy',
  'visual_evidence',
  'new_fact',
  'narrative_role',
  'continuity',
  'production_note',
];

// ---------------------------------------------------------------------------
// AI 导入预览（Import Preview）
// ---------------------------------------------------------------------------

/** 单处修改：某页某字段 before → after。 */
export interface ImagePostImportModified {
  page_no: number;
  field: ImagePostPageField;
  before: string | null;
  after: string | null;
}

/** AI 粘贴解析后的变更预览。确认前不得覆盖正式内容。 */
export interface ImagePostImportPreview {
  /** 新增页面（当前不存在该页码） */
  added: ImagePostPage[];
  /** 修改项（逐页逐字段） */
  modified: ImagePostImportModified[];
  /** 未变化页码（便于视觉表达"未变化 P1 / P2 / P5"） */
  unchanged: number[];
  /** 解析警告（无法识别的内容、被忽略的部分等），不阻断导入 */
  warnings: string[];
}

/** AI 粘贴解析结果：页面列表 + 警告。 */
export interface ImagePostParseResult {
  pages: ImagePostPage[];
  warnings: string[];
}

// ---------------------------------------------------------------------------
// API（V2-P2-C01 前端约定端点；正式端点由 WorkBuddy Phase 2 后端对齐后替换）
// ---------------------------------------------------------------------------

/** 编辑器整篇工作负载：篇目概要 + 页面列表。 */
export interface ImagePostEditorWorkspace {
  item: ImagePostContentItem;
  pages: ImagePostPage[];
}

/** 保存页面：整篇覆盖（前端在确认导入 / 手动保存时提交完整 pages）。 */
export type ImagePostSaveInput = {
  core_expression?: string | null;
  story_line?: string | null;
  pages: ImagePostPage[];
};
