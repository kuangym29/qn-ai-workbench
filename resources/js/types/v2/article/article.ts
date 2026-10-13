// V2 公众号图文文章（非逐页结构）—— 数据合同类型。
//
// 结构原则：图文文章不是「小绿书换皮」。正式主体围绕：
//   标题 / 摘要 / 正文 / 配图建议 / 封面建议
// 共享信息（栏目 / 来源 / 核心表达 / 整篇故事线）由 Content Item 继承，
// 前端只读展示，不要求用户重复录入。
//
// 正式字段命名（R1 收口）：Content Item 共享字段使用 core_message，
// UI 显示文字为「核心表达」，内部统一 core_message。

export interface ArticleContentItem {
  id: number;
  project_id?: number;
  title: string;
  /** 核心表达（共享信息，继承自 Content Item；UI 显示「核心表达」） */
  core_message: string | null;
  /** 整篇故事线（共享信息，继承自 Content Item） */
  story_line: string | null;
  /** 来源展示：栏目名称 */
  column_name?: string | null;
  /** 来源展示：栏目 slug */
  column_slug?: string | null;
}

// ---------------------------------------------------------------------------
// 正式文章字段
// ---------------------------------------------------------------------------

/** 可被后端 preview / apply 作用的字段键。 */
export type ArticleFieldKey =
  | 'headline'
  | 'summary'
  | 'body'
  | 'image_suggestions'
  | 'cover_suggestion';

/** 配图建议：无配图 = 列表为空；position 为空表示位置未指定。 */
export interface ArticleImageSuggestion {
  /** 文中位置（第几段 / 哪个位置后），null = 未指定位置 */
  position?: number | null;
  /** 配图内容建议 */
  description?: string | null;
  note?: string | null;
}

export interface ArticleFields {
  /** 标题 */
  headline: string | null;
  /** 摘要 */
  summary: string | null;
  /** 正文（可长，编辑区需舒适） */
  body: string | null;
  /** 配图建议（空数组 = 无配图） */
  image_suggestions: ArticleImageSuggestion[] | null;
  /** 封面建议 */
  cover_suggestion: string | null;
}

export interface ArticleEditorWorkspace {
  item: ArticleContentItem;
  branch: { id: number; channel: string; format: string };
  article: ArticleFields | null;
}

/** 保存输入：整篇覆盖正式字段。 */
export type ArticleSaveInput = ArticleFields;

// ---------------------------------------------------------------------------
// AI 导入预览合同（正式 B02）
//
// 正式生产链路：前端提交 raw input → 后端 preview → 前端展示 → confirm → 后端 apply。
// 本前端不实现正式解析业务逻辑；以下类型是后端 preview 响应的展示合同。
// 数组 / JSON 按整体字段展示，不在前端再次计算差异。
// ---------------------------------------------------------------------------

export interface ArticlePreviewModified {
  field: ArticleFieldKey | 'title' | 'core_message' | 'story_line';
  before: ArticlePreviewValue;
  after: ArticlePreviewValue;
}

export type ArticlePreviewValue = string | number | boolean | null | ArticlePreviewValue[] | { [key: string]: ArticlePreviewValue };

export interface ArticlePreviewAdded {
  field: ArticleFieldKey;
  after: ArticlePreviewValue;
}

export interface ArticleImportPreview {
  item: { changed: boolean; fields: ArticlePreviewModified[] };
  article: {
    added: ArticlePreviewAdded[];
    modified: ArticlePreviewModified[];
    unchanged: { field: ArticleFieldKey }[];
  };
  /** 解析警告（不阻断） */
  warnings: string[];
}
