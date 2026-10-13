// V2 公众号图文文章（非逐页结构）—— 数据合同类型。
//
// 结构原则：图文文章不是「小绿书换皮」。正式主体围绕：
//   标题 / 摘要 / 正文 / 配图建议 / 封面建议
// 共享信息（栏目 / 来源 / 核心表达 / 整篇故事线）由 Content Item 继承，
// 前端只读展示，不要求用户重复录入。
//
// 命名说明：foundation api/v2.ts 的 ContentItem 实际字段为 core_message，
// 本模块按任务书使用 core_expression；映射差异由 Work 主程序集成时统一。

export interface ArticleContentItem {
  id: number;
  project_id: number;
  title: string;
  /** 核心表达（共享信息，继承自 Content Item） */
  core_expression: string | null;
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
  position: number | null;
  /** 配图内容建议 */
  description: string;
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
  article: ArticleFields;
}

/** 保存输入：整篇覆盖正式字段。 */
export type ArticleSaveInput = ArticleFields;

// ---------------------------------------------------------------------------
// AI 导入预览合同（占位）
//
// 正式生产链路：前端提交 raw input → 后端 preview → 前端展示 → confirm → 后端 apply。
// 本前端不实现正式解析业务逻辑；以下类型是后端 preview 响应的展示合同。
// 开发预览使用显式标注的 MOCK 生成器（见 composables/v2/article/articleCore.ts）。
// ---------------------------------------------------------------------------

export interface ArticlePreviewModified {
  field: ArticleFieldKey;
  /** 展示用字段中文名 */
  label: string;
  before: string | null;
  after: string | null;
}

export interface ArticlePreviewAdded {
  label: string;
  detail: string | null;
}

export interface ArticleImportPreview {
  /** 新增内容（如新增配图建议） */
  added: ArticlePreviewAdded[];
  /** 修改（逐字段 before → after） */
  modified: ArticlePreviewModified[];
  /** 未变化字段中文名列表 */
  unchanged: string[];
  /** 解析警告（不阻断） */
  warnings: string[];
}

// ---------------------------------------------------------------------------
// 开发预览 MOCK 合同（DEV ONLY，明确不进入生产）
// ---------------------------------------------------------------------------

/** 开发预览的 preview/apply 提供者；正式页面使用后端版本。 */
export interface ArticleImportProviders {
  preview: (raw: string) => Promise<ArticleImportPreview>;
  apply: (raw: string) => Promise<ArticleEditorWorkspace>;
}
