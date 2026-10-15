// V2 人物 / 场景复用资产索引 —— 页面状态 composable。
//
// 正式链路（只读）：
//   GET loadWorkspace(projectId) → 人物 / 场景分组
//   → Tab 切换（人物 / 场景）+ 状态筛选（全部 / 已有 / 缺失）
//
// 第一阶段没有上传 / 编辑 / 生成；「准备建立」由组件 emit
// （prepareCharacter / prepareScene），本 composable 不调用 Work、不创建生成 API。
//
// 生产模块只依赖 assetIndexApi（0 引用 DEV Mock）；DEV 预览 / 测试通过第 2 参注入 client。
import { computed, ref } from 'vue';
import type { AssetIndexClient } from './assetIndexApi';
import { assetIndexApi } from './assetIndexApi';
import { countByStatus, filterItems, getItemsByType } from './assetIndexCore';
import { firstErrorMessage } from '../../../api/errors';
import type {
  AssetStatusFilter,
  AssetTab,
  ReusableAssetWorkspace,
} from '../../../types/v2/reusableAssets/reusableAssets';

type Id = number | string;

export function useReusableAssetIndex(projectId: Id, client: AssetIndexClient = assetIndexApi) {
  const workspace = ref<ReusableAssetWorkspace | null>(null);
  const loading = ref(true);
  const loadError = ref('');

  const tab = ref<AssetTab>('character');
  const statusFilter = ref<AssetStatusFilter>('all');

  const characters = computed(() => workspace.value?.characters ?? []);
  const scenes = computed(() => workspace.value?.scenes ?? []);

  const characterCounts = computed(() => countByStatus(characters.value));
  const sceneCounts = computed(() => countByStatus(scenes.value));

  /** 当前 Tab 的全量项（筛选前）。 */
  const tabItems = computed(() =>
    workspace.value ? getItemsByType(workspace.value, tab.value) : [],
  );

  /** 当前 Tab + 状态筛选后的可见项。 */
  const visibleItems = computed(() => filterItems(tabItems.value, statusFilter.value));

  /** 当前 Tab 计数（用于筛选行 / Tab 标签）。 */
  const tabCounts = computed(() =>
    tab.value === 'character' ? characterCounts.value : sceneCounts.value,
  );

  /** 筛选后是否为空（区分加载失败与无数据）。 */
  const isEmpty = computed(() => !loading.value && !loadError.value && visibleItems.value.length === 0);

  async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    try {
      workspace.value = await client.loadWorkspace(projectId);
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
    } finally {
      loading.value = false;
    }
  }

  function setTab(next: AssetTab): void {
    tab.value = next;
  }

  function setStatusFilter(next: AssetStatusFilter): void {
    statusFilter.value = next;
  }

  return {
    workspace,
    loading,
    loadError,
    tab,
    statusFilter,
    characters,
    scenes,
    characterCounts,
    sceneCounts,
    tabItems,
    visibleItems,
    tabCounts,
    isEmpty,
    load,
    setTab,
    setStatusFilter,
  };
}
