// V2 公众号图文文章 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责边界（R1 收口）：
//   正式 AI 解析由后端 preview 完成，本模块【不实现】正式解析业务逻辑。
//   这里只提供：
//     1. normalizeArticleFields()    字段归一化（null / 空串 / 数组）。
//     2. imageSuggestions 列表纯函数（增 / 删 / 改）。
//   DEV 预览 Mock（buildMockImportPreview）已迁移到独立文件
//   composables/v2/article/articleDevMock.ts（DEV ONLY），正式生产模块不引用。
import type {
  ArticleFieldKey,
  ArticleFields,
  ArticleImageSuggestion,
} from '../../../types/v2/article/article';

export const ARTICLE_FIELD_LABELS: Record<ArticleFieldKey, string> = {
  headline: '标题',
  summary: '摘要',
  body: '正文',
  image_suggestions: '配图建议',
  cover_suggestion: '封面建议',
};

// ---------------------------------------------------------------------------
// 字段归一化
// ---------------------------------------------------------------------------

function normalizeNullable(v: unknown): string | null {
  if (typeof v !== 'string') return null;
  const t = v.trim();
  return t === '' ? null : v; // 保留原文内部空白，仅以首尾 trim 判断空
}

export function normalizeImageSuggestions(v: unknown): ArticleImageSuggestion[] | null {
  if (!Array.isArray(v)) return null;
  if (v.length === 0) return null; // 无配图
  const items: ArticleImageSuggestion[] = [];
  for (const raw of v) {
    if (typeof raw !== 'object' || raw === null) continue;
    const o = raw as Record<string, unknown>;
    const description = typeof o.description === 'string' ? o.description.trim() : '';
    if (!description) continue;
    const pos = o.position == null ? null : Number(o.position);
    items.push({
      position: Number.isInteger(pos) && pos !== null && pos >= 1 ? pos : null,
      description,
      note: normalizeNullable(o.note),
    });
  }
  return items.length > 0 ? items : null;
}

/** 把任意来源的输入归一化为正式 ArticleFields（长正文原样保留）。 */
export function normalizeArticleFields(raw: Partial<ArticleFields> | null | undefined): ArticleFields {
  return {
    headline: normalizeNullable(raw?.headline),
    summary: normalizeNullable(raw?.summary),
    body: typeof raw?.body === 'string' ? (raw.body.trim() === '' ? null : raw.body) : null,
    image_suggestions: normalizeImageSuggestions(raw?.image_suggestions),
    cover_suggestion: normalizeNullable(raw?.cover_suggestion),
  };
}

// ---------------------------------------------------------------------------
// 配图建议列表纯函数
// ---------------------------------------------------------------------------

export function addImageSuggestion(
  list: ArticleImageSuggestion[] | null,
  item: ArticleImageSuggestion,
): ArticleImageSuggestion[] {
  return [...(list ?? []), item];
}

export function removeImageSuggestion(
  list: ArticleImageSuggestion[] | null,
  index: number,
): ArticleImageSuggestion[] | null {
  const base = list ?? [];
  if (index < 0 || index >= base.length) return list;
  const next = base.filter((_, i) => i !== index);
  return next.length > 0 ? next : null;
}

export function updateImageSuggestion(
  list: ArticleImageSuggestion[] | null,
  index: number,
  patch: Partial<ArticleImageSuggestion>,
): ArticleImageSuggestion[] | null {
  const base = list ?? [];
  if (index < 0 || index >= base.length) return list;
  return base.map((item, i) => (i === index ? { ...item, ...patch } : item));
}
