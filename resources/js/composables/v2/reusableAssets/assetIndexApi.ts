// V2 人物 / 场景复用资产索引 —— 生产 API client（独立，不引用任何 DEV Mock）。
//
// 预留正式端点（当前无正式后端，状态 WAITING_FOR_BACKEND；不改 routes）：
//   GET /api/projects/{project}/reusable-assets
//       → { data: ReusableAssetWorkspace }
//
// 索引为只读轻量页面：第一阶段没有上传 / 编辑 / 生成 API；
// 「准备建立」按钮只 emit，不调用 Work。
import { http } from '../../../api/http';
import type { ReusableAssetWorkspace } from '../../../types/v2/reusableAssets/reusableAssets';

/** 可注入的 HTTP transport（生产用 http 单例；测试 / DEV 预览注入独立 client）。 */
export interface AssetIndexTransport {
  get(url: string): Promise<{ data: unknown }>;
}

type Id = number | string;

export function createAssetIndexClient(transport: AssetIndexTransport) {
  const base = (projectId: Id): string =>
    `/api/projects/${Number(projectId)}/reusable-assets`;

  return {
    /** 加载复用索引工作负载（人物 / 场景分组）。 */
    async loadWorkspace(projectId: Id): Promise<ReusableAssetWorkspace> {
      const res = await transport.get(base(projectId));
      return (res.data as { data: ReusableAssetWorkspace }).data;
    },
  };
}

export type AssetIndexClient = ReturnType<typeof createAssetIndexClient>;

/** 生产单例（正式 composable 使用；DEV 测试 / 预览注入独立 client）。 */
export const assetIndexApi: AssetIndexClient = createAssetIndexClient(
  http as unknown as AssetIndexTransport,
);
