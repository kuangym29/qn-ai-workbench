// V2 图片批次生产 —— 可选复用资产选项 composable（P5-I03）。
//
// 数据来源只有正式 GET /api/projects/{project}/reusable-assets（P5-I02），
// 不在 ImageBatch 内 quick-create，避免重复 P5-I02 管理 UI。
import { ref } from 'vue';
import type { AssetOptionClient, AssetOption } from './assetOptions';
import type { ReusableAssetType } from '../../../types/v2/reusableAssets/reusableAssets';

export function useAssetOptions(client: AssetOptionClient) {
  const options = ref<Record<ReusableAssetType, AssetOption[]>>({ character: [], scene: [] });
  const loading = ref(false);
  const error = ref('');

  async function load(projectId: number | string): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
      options.value = await client.loadOptions(projectId);
    } catch (e) {
      error.value = e instanceof Error ? e.message : '资产选项加载失败';
    } finally {
      loading.value = false;
    }
  }

  return { options, loading, error, load };
}

export type AssetOptionsState = ReturnType<typeof useAssetOptions>;
