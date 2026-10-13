// V2 公众号图文文章 —— API adapter（R1 合同收口：branch-aware）。
//
// 正式端点（冻结，R1）：
//   GET  /api/projects/{project}/items/{item}/branches/{branch}/article
//   PUT  /api/projects/{project}/items/{item}/branches/{branch}/article
//   POST /api/projects/{project}/items/{item}/branches/{branch}/article/preview
//   POST /api/projects/{project}/items/{item}/branches/{branch}/article/apply
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

function base(
  projectId: number | string,
  itemId: number | string,
  branchId: number | string,
): string {
  return `/api/projects/${Number(projectId)}/items/${Number(itemId)}/branches/${Number(branchId)}/article`;
}

export const articleApi = {
  /** 加载编辑器工作负载（共享信息 + 正式字段）。 */
  async loadWorkspace(
    projectId: number | string,
    itemId: number | string,
    branchId: number | string,
  ): Promise<ArticleEditorWorkspace> {
    const res = await http.get<ApiResponse<ArticleEditorWorkspace>>(
      base(projectId, itemId, branchId),
    );
    return res.data.data;
  },

  /** 保存整篇正式字段。 */
  async save(
    projectId: number | string,
    itemId: number | string,
    branchId: number | string,
    input: ArticleSaveInput,
  ): Promise<ArticleEditorWorkspace> {
    const res = await http.put<ApiResponse<ArticleEditorWorkspace>>(
      base(projectId, itemId, branchId),
      input,
    );
    return res.data.data;
  },

  /** AI 导入预览：提交 raw，后端解析。 */
  async preview(
    projectId: number | string,
    itemId: number | string,
    branchId: number | string,
    raw: string,
  ): Promise<ArticleImportPreview> {
    const res = await http.post<ApiResponse<ArticleImportPreview>>(
      `${base(projectId, itemId, branchId)}/preview`,
      { raw },
    );
    return res.data.data;
  },

  /** AI 导入确认：后端 apply 后返回最新工作负载。 */
  async apply(
    projectId: number | string,
    itemId: number | string,
    branchId: number | string,
    raw: string,
  ): Promise<ArticleEditorWorkspace> {
    const res = await http.post<ApiResponse<ArticleEditorWorkspace>>(
      `${base(projectId, itemId, branchId)}/apply`,
      { raw },
    );
    return res.data.data;
  },
};
