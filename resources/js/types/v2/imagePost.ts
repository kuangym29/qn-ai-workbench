import type { PageType } from '../../api/types';

// ---------------------------------------------------------------------------
// 篇目级（Content Item）
// ---------------------------------------------------------------------------

/** 图片文章篇目编辑视图。project_id / topic_id 等归属字段由服务端持有，前端只读。 */
export interface ImagePostContentItem {
  id: number;
  title: string;
  /** 核心表达 */
  core_message: string | null;
  /** 整篇故事线 */
  story_line: string | null;

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

export interface ImagePostChange { field: string; before: string | null; after: string | null }
export interface ImagePostWarning { code: string; message: string; page_no?: number }
export interface ImagePostImportPreview {
 item: { changed: boolean; fields: ImagePostChange[] };
 pages: { added: ImagePostPage[]; modified: { page_no: number; page_type: PageType; changes: ImagePostChange[] }[]; unchanged: { page_no: number; page_type: PageType }[] };
 warnings: ImagePostWarning[];
}
export interface ImagePostEditorWorkspace { item: ImagePostContentItem; branch: { id: number; channel: string; format: string }; pages: ImagePostPage[] }
export interface ImagePostSaveInput { title?: string; core_message?: string | null; story_line?: string | null; pages: ImagePostPage[] }
export interface ImagePostSource { topic: { id: number; title: string } | null; inspiration: { id: number; body: string } | null }
