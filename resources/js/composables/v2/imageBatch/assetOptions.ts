// V2 图片批次生产 —— 可选复用资产的极薄 adapter（P5-I03）。
//
// 产品原则：ImageBatch 不复制第二份 Reusable Asset Index。
// 选择器数据源直接复用 P5-I02 已有的正式 GET：
//   GET /api/projects/{project}/reusable-assets → {characters, scenes}
// 本文件只做「按类型取列表」这一层薄封装，不新增任何 /image-batch/assets/options 端点。
//
// 生产链路 0 DEV Mock：正式页面的选项只可能来自正式 GET。
import { createAssetIndexClient, type AssetIndexTransport } from '../reusableAssets/assetIndexApi';
import type { ReusableAssetItem, ReusableAssetType } from '../../../types/v2/reusableAssets/reusableAssets';

/** 选择器里的一条可选资产（只带 UI 需要的展示字段，不塞整个 ReusableAsset 对象进 unit）。 */
export interface AssetOption {
  id: number;
  name: string;
  /** 业务中文状态：已有 / 待建立（绝不暴露 existing / missing）。 */
  statusLabel: string;
}

const STATUS_LABELS: Record<string, string> = { existing: '已有', missing: '待建立' };

export function statusLabelOf(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

export function createAssetOptionClient(transport: AssetIndexTransport) {
  const client = createAssetIndexClient(transport);

  return {
    /** 当前 Project 的可选人物 / 场景（缺失资产同样可选：资产检查需要告诉用户「要用但母版还没建立」）。 */
    async loadOptions(projectId: number | string): Promise<Record<ReusableAssetType, AssetOption[]>> {
      const workspace = await client.loadWorkspace(projectId);
      const toOptions = (items: ReusableAssetItem[]): AssetOption[] =>
        items.map((item) => ({ id: Number(item.id), name: item.name, statusLabel: statusLabelOf(item.status) }));

      return { character: toOptions(workspace.characters), scene: toOptions(workspace.scenes) };
    },
  };
}

export type AssetOptionClient = ReturnType<typeof createAssetOptionClient>;
