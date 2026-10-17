// V2 人物 / 场景复用资产索引 —— 生产 API client（独立，不引用任何 DEV Mock）。
//
// 正式端点：
//   GET    /api/projects/{project}/reusable-assets             → ReusableAssetWorkspace 本体
//   POST   /api/projects/{project}/reusable-assets             → 201 + 单个 ReusableAssetItem
//   PATCH  /api/projects/{project}/reusable-assets/{asset}     → 200 + 更新后的 ReusableAssetItem
//
// 注意：GET 响应体就是 workspace 本体（与 V2 Video / ImageBatch 一致），顶层没有 data
// 包裹键，直接读取 res.data（前端测试已锁死此形状）。
// POST / PATCH 同样直接返回单个 item 本体（无 data 包裹），与 GET 保持同一域契约。
//
// P5-I02 只补「新增 / 编辑」：没有 DELETE、没有上传、没有 rename / change type。
import { http } from '../../../api/http';
import type {
  ReusableAssetCreatePayload,
  ReusableAssetItem,
  ReusableAssetUpdatePayload,
  ReusableAssetWorkspace,
} from '../../../types/v2/reusableAssets/reusableAssets';

export type {
  ReusableAssetCreatePayload,
  ReusableAssetUpdatePayload,
} from '../../../types/v2/reusableAssets/reusableAssets';

/** 可注入的 HTTP transport（生产用 http 单例；测试 / DEV 预览注入独立 client）。 */
export interface AssetIndexTransport {
  get(url: string): Promise<{ data: unknown }>;
  post(url: string, body: unknown): Promise<{ data: unknown }>;
  patch(url: string, body: unknown): Promise<{ data: unknown }>;
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

    /** 新增人物 / 场景；响应体即单个 item 本体。 */
    async createAsset(projectId: Id, payload: ReusableAssetCreatePayload): Promise<ReusableAssetItem> {
      const res = await transport.post(base(projectId), payload);
      return res.data as ReusableAssetItem;
    },

    /** 编辑 status / 预览图 / 说明；响应体即更新后的单个 item 本体。 */
    async updateAsset(
      projectId: Id,
      assetId: Id,
      payload: ReusableAssetUpdatePayload,
    ): Promise<ReusableAssetItem> {
      const res = await transport.patch(`${base(projectId)}/${Number(assetId)}`, payload);
      return res.data as ReusableAssetItem;
    },
  };
}

export type AssetIndexClient = ReturnType<typeof createAssetIndexClient>;

/** 生产单例（正式 composable 使用；DEV 测试 / 预览注入独立 client）。 */
export const assetIndexApi: AssetIndexClient = createAssetIndexClient(
  http as unknown as AssetIndexTransport,
);
