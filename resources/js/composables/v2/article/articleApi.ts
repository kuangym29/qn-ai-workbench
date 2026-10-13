// V2 公众号图文文章 —— API adapter。
//
// 端点按 V2 foundation 风格约定（/api/projects/{project}/v2/article/{item}），
// 正式端点由 WorkBuddy Phase 2 后端实现后对齐；路径不同仅需替换 base。
//
// 正式 AI 导入链路（后端为唯一解析者）：
//   POST .../preview  raw → ArticleImportPreview
//   POST .../apply    raw → ArticleEditorWorkspace
// 前端不实现解析逻辑，只提交 raw 并展示后端结果。
import { http } from '../../../api/http';
import type { ApiResponse } from '../../../api/types';
import type {
  ArticleEditorWorkspace,
  ArticleImportPreview,
  ArticleSaveInput,
} from '../../../types/v2/article/article';

function base(projectId: number | string, itemId: number | string): string {
  return `/api/projects/${Number(projectId)}/v2/article/${Number(itemId)}`;
}

export const articleApi = {
  /** 加载编辑器工作负载（共享信息 + 正式字段）。 */
  async loadWorkspace(
    projectId: number | string,
    itemId: number | string,
  ): Promise<ArticleEditorWorkspace> {
    const res = await http.get<ApiResponse<ArticleEditorWorkspace>>(base(projectId, itemId));
    return res.data.data;
  },

  /** 保存整篇正式字段。 */
  async save(
    projectId: number | string,
    itemId: number | string,
    input: ArticleSaveInput,
  ): Promise<ArticleEditorWorkspace> {
    const res = await http.put<ApiResponse<ArticleEditorWorkspace>>(base(projectId, itemId), input);
    return res.data.data;
  },

  /** AI 导入预览：提交 raw，后端解析。 */
  async preview(
    projectId: number | string,
    itemId: number | string,
    raw: string,
  ): Promise<ArticleImportPreview> {
    const res = await http.post<ApiResponse<ArticleImportPreview>>(
      `${base(projectId, itemId)}/preview`,
      { raw },
    );
    return res.data.data;
  },

  /** AI 导入确认：后端 apply 后返回最新工作负载。 */
  async apply(
    projectId: number | string,
    itemId: number | string,
    raw: string,
  ): Promise<ArticleEditorWorkspace> {
    const res = await http.post<ApiResponse<ArticleEditorWorkspace>>(
      `${base(projectId, itemId)}/apply`,
      { raw },
    );
    return res.data.data;
  },
};
