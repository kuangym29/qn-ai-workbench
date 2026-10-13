// V2 视频号视频制作 —— 生产 API client（独立，不引用任何 DEV Mock）。
//
// R1 正式端点（branch-aware，V2 foundation 风格；由 WorkBuddy B04 按此合同实现）：
//   GET  /api/projects/{p}/items/{i}/branches/{b}/video
//        → VideoEditorWorkspace（含系统计算：总时长 / 时间轴）
//   PUT  /api/projects/{p}/items/{i}/branches/{b}/video
//        body: VideoSaveInput（batch_instruction / narration / units[source_page_id…]）
//        保存用户可编辑字段；保存成功后前端重新 GET，不乐观伪造服务端状态。
//   POST /api/projects/{p}/items/{i}/branches/{b}/video/source-change
//        body: { action: 'keep' } —— 记录「继续沿用」决策；成功后前端重新 GET。
//
// 本任务只实现 UI；生成按钮（复制任务 / 先做试样 / 选择片段生成）保持
// UI / emit / disabled pending integration，不发明生成 API。
import { http } from '../../../api/http';
import type {
  VideoEditorWorkspace,
  VideoSaveInput,
  VideoSourceChangeInput,
} from '../../../types/v2/video/video';

/** 可注入的 HTTP transport（生产用 http 单例；测试 / DEV 预览注入独立 client）。 */
export interface VideoTransport {
  get(url: string): Promise<{ data: unknown }>;
  put(url: string, input: unknown): Promise<{ data: unknown }>;
  post(url: string, input?: unknown): Promise<{ data: unknown }>;
}

type Id = number | string;

export function createVideoClient(transport: VideoTransport) {
  const base = (projectId: Id, itemId: Id, branchId: Id): string =>
    `/api/projects/${Number(projectId)}/items/${Number(itemId)}/branches/${Number(branchId)}/video`;

  return {
    /** 加载视频制作工作负载（含系统计算：总时长 / 时间轴）。 */
    async loadWorkspace(projectId: Id, itemId: Id, branchId: Id): Promise<VideoEditorWorkspace> {
      const res = await transport.get(base(projectId, itemId, branchId));
      return (res.data as { data: VideoEditorWorkspace }).data;
    },

    /** 保存用户可编辑字段（白名单 VideoSaveInput；成功后调用方重新 GET）。 */
    async save(projectId: Id, itemId: Id, branchId: Id, input: VideoSaveInput): Promise<void> {
      await transport.put(base(projectId, itemId, branchId), input);
    },

    /** 记录来源变化决策（继续沿用 keep）；成功后调用方重新 GET。 */
    async resolveSourceChange(
      projectId: Id,
      itemId: Id,
      branchId: Id,
      input: VideoSourceChangeInput,
    ): Promise<void> {
      await transport.post(base(projectId, itemId, branchId) + '/source-change', input);
    },
  };
}

export type VideoClient = ReturnType<typeof createVideoClient>;

/** 生产单例（正式 composable 使用；DEV 测试 / 预览注入独立 client）。 */
export const videoApi: VideoClient = createVideoClient(http as unknown as VideoTransport);
