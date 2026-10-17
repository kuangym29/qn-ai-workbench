// V2 图片批次生产 —— 可选复用资产选项 composable（P5-I03）。
//
// 数据来源只有正式 GET /api/projects/{project}/reusable-assets（P5-I02），
// 不在 ImageBatch 内 quick-create，避免重复 P5-I02 管理 UI。
//
// P5-I03-R1：请求序号守卫。
// 编辑器整个生命周期复用同一个 composable 实例，而 project/item/branch 变化会连续
// 触发 load()。若 A 项目请求在途时切到 B 项目，B 先返回、A 后返回，旧实现会让 A
// 的结果覆盖 B（错误）。这里用单调递增的 requestId 保证只有最新一次请求能写回
// options / error / loading；新请求开始时先清空 options，避免旧项目资产短暂冒充
// 新项目的可选项。不引入 AbortController / 通用网络层。
import { ref } from 'vue';
import type { AssetOptionClient, AssetOption } from './assetOptions';
import type { ReusableAssetType } from '../../../types/v2/reusableAssets/reusableAssets';

export function useAssetOptions(client: AssetOptionClient) {
  const options = ref<Record<ReusableAssetType, AssetOption[]>>({ character: [], scene: [] });
  const loading = ref(false);
  const error = ref('');
  /** 单调递增的请求序号：只有最新一次 load 允许写回任何状态。 */
  let requestId = 0;

  async function load(projectId: number | string): Promise<void> {
    const current = ++requestId;
    // 新作用域开始：先清空旧作用域选项并进入 loading，
    // 避免用户在 B 项目请求未完成时误选 A 项目的资产。
    options.value = { character: [], scene: [] };
    loading.value = true;
    error.value = '';
    try {
      const result = await client.loadOptions(projectId);
      if (current !== requestId) return;
      options.value = result;
    } catch (e) {
      // 旧请求的失败不得覆盖新请求已成功的状态。
      if (current !== requestId) return;
      error.value = e instanceof Error ? e.message : '资产选项加载失败';
    } finally {
      // 旧请求完成时不得把新请求的 loading 提前置 false。
      if (current === requestId) loading.value = false;
    }
  }

  return { options, loading, error, load };
}

export type AssetOptionsState = ReturnType<typeof useAssetOptions>;
