// V2 公众号图文文章 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责边界（重要）：
//   正式 AI 解析由后端 preview 完成，本模块【不实现】正式解析业务逻辑。
//   这里只提供：
//     1. normalizeArticleFields()    字段归一化（null / 空串 / 数组）。
//     2. imageSuggestions 列表纯函数（增 / 删 / 改）。
//     3. buildMockImportPreview()   DEV ONLY 的开发预览模拟，明确标注 MOCK，
//        仅用于本地浏览器验收与组件测试，永不进入生产路径。
import type {
  ArticleFieldKey,
  ArticleFields,
  ArticleImageSuggestion,
  ArticleImportPreview,
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

// ---------------------------------------------------------------------------
// DEV ONLY：Mock Preview 生成器
// ---------------------------------------------------------------------------

/**
 * DEV ONLY MOCK —— 不是正式解析业务逻辑。
 *
 * 用于本地浏览器验收与前端展示测试。规则刻意简单可预期：
 *   - 空输入 → warnings；
 *   - 「标题：」「摘要：」「正文：」「封面：」行 → 对应字段 modified；
 *   - 「配图：」行 → added 一条配图建议；
 *   - 其余情况 → unchanged 全部字段 + DEV 提示 warning。
 *
 * 正式生产时后端 preview 会替换此模拟（useArticleEditor 的 previewProvider）。
 */
export function buildMockImportPreview(
  raw: string,
  current: ArticleFields,
  baseUnchanged: ArticleFieldKey[] = [
    'headline',
    'summary',
    'body',
    'cover_suggestion',
    'image_suggestions',
  ],
): ArticleImportPreview {
  const trimmed = raw.trim();
  const warnings: string[] = [];
  if (!trimmed) {
    warnings.push('粘贴内容为空。');
    return { added: [], modified: [], unchanged: [], warnings };
  }

  const modified: ArticleImportPreview['modified'] = [];
  const added: ArticleImportPreview['added'] = [];
  const touched = new Set<ArticleFieldKey>();

  for (const line of trimmed.split(/\r?\n/)) {
    const t = line.trim();
    if (!t) continue;
    const headline = t.match(/^标题[:：]\s*(.+)$/);
    if (headline) {
      touched.add('headline');
      modified.push({
        field: 'headline',
        label: ARTICLE_FIELD_LABELS.headline,
        before: current.headline,
        after: headline[1].trim() || null,
      });
      continue;
    }
    const summary = t.match(/^摘要[:：]\s*(.+)$/);
    if (summary) {
      touched.add('summary');
      modified.push({
        field: 'summary',
        label: ARTICLE_FIELD_LABELS.summary,
        before: current.summary,
        after: summary[1].trim() || null,
      });
      continue;
    }
    const body = t.match(/^正文[:：]\s*(.+)$/);
    if (body) {
      touched.add('body');
      modified.push({
        field: 'body',
        label: ARTICLE_FIELD_LABELS.body,
        before: current.body,
        after: body[1].trim() || null,
      });
      continue;
    }
    const cover = t.match(/^封面[:：]\s*(.+)$/);
    if (cover) {
      touched.add('cover_suggestion');
      modified.push({
        field: 'cover_suggestion',
        label: ARTICLE_FIELD_LABELS.cover_suggestion,
        before: current.cover_suggestion,
        after: cover[1].trim() || null,
      });
      continue;
    }
    const image = t.match(/^配图[:：]\s*(.+)$/);
    if (image) {
      touched.add('image_suggestions');
      added.push({ label: '新增配图建议', detail: image[1].trim() || null });
      continue;
    }
    warnings.push(`未识别行（DEV Mock 忽略，正式解析由后端提供）：${t.slice(0, 40)}`);
  }

  const unchanged = baseUnchanged
    .filter((f) => !touched.has(f))
    .map((f) => ARTICLE_FIELD_LABELS[f]);
  warnings.push('（DEV 预览）当前为 Mock Preview，正式解析由后端 preview 接口提供。');
  return { added, modified, unchanged, warnings };
}
