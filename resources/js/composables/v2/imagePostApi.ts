// V2 图片文章编辑器 —— API adapter。
//
// 端点按 V2 foundation 风格约定设计（/api/projects/{project}/v2/image-post/{item}），
// 待 WorkBuddy Phase 2 后端正式实现后对齐；如果真实端点路径不同，由 Work 主程序
// 集成时在此文件内替换 base 即可，页面与组件不感知。
//
// 响应统一解包 {"data": ...}（与 api/v2.ts 一致）。
import { http } from '../../api/http';
import type { ApiResponse } from '../../api/types';
import type {
  ImagePostEditorWorkspace,
  ImagePostSaveInput,
} from '../../types/v2/imagePost';

function base(projectId: number | string, itemId: number | string): string {
  return `/api/projects/${Number(projectId)}/v2/image-post/${Number(itemId)}`;
}

export const imagePostApi = {
  /** 加载编辑器整篇工作负载（篇目概要 + 页面列表）。 */
  async loadWorkspace(
    projectId: number | string,
    itemId: number | string,
  ): Promise<ImagePostEditorWorkspace> {
    const res = await http.get<ApiResponse<ImagePostEditorWorkspace>>(base(projectId, itemId));
    return res.data.data;
  },

  /** 保存整篇（核心表达 / 故事线 / 页面列表全量覆盖）。 */
  async save(
    projectId: number | string,
    itemId: number | string,
    input: ImagePostSaveInput,
  ): Promise<ImagePostEditorWorkspace> {
    const res = await http.put<ApiResponse<ImagePostEditorWorkspace>>(
      base(projectId, itemId),
      input,
    );
    return res.data.data;
  },
};
