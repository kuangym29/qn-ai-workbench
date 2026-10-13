// V2 视频号视频制作 —— API adapter。
//
// 端点按 V2 foundation 风格约定（/api/projects/{project}/v2/video/{item}），
// 正式端点由 WorkBuddy Phase 2 后端实现后对齐；路径不同仅需替换 base。
// 本任务只实现 UI；按钮（复制任务 / 先做试样 / 选择片段生成）为 emit/mock，
// 不开发真实视频生成。
import { http } from '../../../api/http';
import type { ApiResponse } from '../../../api/types';
import type { VideoEditorWorkspace, VideoSaveInput } from '../../../types/v2/video/video';

function base(projectId: number | string, itemId: number | string): string {
  return `/api/projects/${Number(projectId)}/v2/video/${Number(itemId)}`;
}

export const videoApi = {
  /** 加载视频制作工作负载（含系统计算：总时长 / 时间轴）。 */
  async loadWorkspace(
    projectId: number | string,
    itemId: number | string,
  ): Promise<VideoEditorWorkspace> {
    const res = await http.get<ApiResponse<VideoEditorWorkspace>>(base(projectId, itemId));
    return res.data.data;
  },

  /** 保存用户可编辑字段。 */
  async save(
    projectId: number | string,
    itemId: number | string,
    input: VideoSaveInput,
  ): Promise<VideoEditorWorkspace> {
    const res = await http.put<ApiResponse<VideoEditorWorkspace>>(base(projectId, itemId), input);
    return res.data.data;
  },
};
