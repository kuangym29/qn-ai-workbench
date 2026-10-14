// V2 人物 / 场景复用资产索引 —— 生产 API client（独立，不引用任何 DEV Mock）。
//
// 正式端点（I06A 已接通 B06 后端）：
//   GET /api/projects/{project}/reusable-assets
//       → ReusableAssetWorkspace 本体：{ characters: [...], scenes: [...] }
//
// 注意：响应体就是 workspace 本体（与 V2 Video / ImageBatch 一致），
// 顶层没有 data 包裹键，直接读取 res.data（前端测试已锁死此形状）。
//
// 索引为只读轻量页面：没有上传 / 编辑 / 生成 API；
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
    /** 加载复用索引工作负载（人物 / 场景分组）；响应体即 workspace 本体。 */
    async loadWorkspace(projectId: Id): Promise<ReusableAssetWorkspace> {
      const res = await transport.get(base(projectId));
      return res.data as ReusableAssetWorkspace;
    },
  };
}

export type AssetIndexClient = ReturnType<typeof createAssetIndexClient>;

/** 生产单例（正式 composable 使用；DEV 测试 / 预览注入独立 client）。 */
export const assetIndexApi: AssetIndexClient = createAssetIndexClient(
  http as unknown as AssetIndexTransport,
);
