// V2 公众号图文文章 —— DEV 预览 Mock 生成器（DEV ONLY，独立于正式生产模块）。
//
// R1 合同收口：正式生产 composable（articleCore / useArticleEditor / articleApi）
// 绝不引用本文件。本文件只被：
//   - ArticleDevPreviewApp.vue（浏览器开发预览）
//   - articleDevMock.test.ts（DEV 展示测试）
// 引用。正式路径只能 raw → 后端 preview → UI → 后端 apply。
import type {
  ArticleFieldKey,
  ArticleFields,
  ArticleImportPreview,
} from '../../../types/v2/article/article';
import { ARTICLE_FIELD_LABELS } from './articleCore.ts';

/**
 * DEV ONLY MOCK —— 不是正式解析业务逻辑。
 *
 * 规则刻意简单可预期：
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
