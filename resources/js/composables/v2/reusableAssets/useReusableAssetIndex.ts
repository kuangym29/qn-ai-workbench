// V2 人物 / 场景复用资产索引 —— 页面状态 composable。
//
// 正式链路：
//   GET  loadWorkspace(projectId)  → 人物 / 场景分组
//   POST createAsset(...)          → 新增人物 / 场景（成功后主列表立即重新加载正式 GET 数据）
//   PATCH updateAsset(...)         → 编辑 status / 预览图 / 说明（同样重新加载）
//   → Tab 切换（人物 / 场景）+ 状态筛选（全部 / 已有 / 缺失）
//
// P5-I02：页面不再只是只读。新增 / 编辑都走本 composable，
// 拒绝复杂 optimistic cache：保存成功后一律重新拉取服务器正式数据。
//
// 生产模块只依赖 assetIndexApi（0 引用 DEV Mock）；DEV 预览 / 测试通过第 2 参注入 client。
import { computed, ref } from 'vue';
import type {
  AssetIndexClient,
  ReusableAssetCreatePayload,
  ReusableAssetUpdatePayload,
} from './assetIndexApi';
import { assetIndexApi } from './assetIndexApi';
import { countByStatus, filterItems, getItemsByType } from './assetIndexCore';
import { firstErrorMessage } from '../../../api/errors';
import type {
  AssetStatusFilter,
  AssetTab,
  ReusableAssetItem,
  ReusableAssetWorkspace,
} from '../../../types/v2/reusableAssets/reusableAssets';

type Id = number | string;

/** projectId 可以是固定值，也可以是 getter（页面 props 变化时实时生效，避免旧 Project 污染）。 */
export type ProjectIdSource = Id | (() => Id);

export function useReusableAssetIndex(projectId: ProjectIdSource, client: AssetIndexClient = assetIndexApi) {
  const workspace = ref<ReusableAssetWorkspace | null>(null);
  const loading = ref(true);
  const loadError = ref('');

  const saving = ref(false);
  const saveError = ref('');

  const tab = ref<AssetTab>('character');
  const statusFilter = ref<AssetStatusFilter>('all');

  /** 过期响应守卫：只有最新一次请求可以写入状态（防止慢响应覆盖新数据 / 跨 Project 污染）。 */
  const revision = ref(0);

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

  function currentProjectId(): Id {
    return typeof projectId === 'function' ? projectId() : projectId;
  }

  async function load(options: { silent?: boolean } = {}): Promise<void> {
    const myRevision = ++revision.value;
    if (!options.silent) loading.value = true;
    loadError.value = '';
    try {
      const result = await client.loadWorkspace(currentProjectId());
      if (myRevision !== revision.value) return; // 过期响应：丢弃
      workspace.value = result;
    } catch (e) {
      if (myRevision !== revision.value) return;
      loadError.value = firstErrorMessage(e) ?? '加载失败';
    } finally {
      if (myRevision === revision.value) loading.value = false;
    }
  }

  /** 新增人物 / 场景；成功后重新拉取正式数据。失败时抛出（交由弹层展示并保持打开）。 */
  async function createAsset(payload: ReusableAssetCreatePayload): Promise<ReusableAssetItem> {
    saving.value = true;
    saveError.value = '';
    try {
      const item = await client.createAsset(currentProjectId(), payload);
      await load({ silent: true });
      return item;
    } catch (e) {
      saveError.value = firstErrorMessage(e) ?? '保存失败';
      throw e;
    } finally {
      saving.value = false;
    }
  }

  /** 编辑 status / 预览图 / 说明；成功后重新拉取正式数据。 */
  async function updateAsset(assetId: Id, payload: ReusableAssetUpdatePayload): Promise<ReusableAssetItem> {
    saving.value = true;
    saveError.value = '';
    try {
      const item = await client.updateAsset(currentProjectId(), assetId, payload);
      await load({ silent: true });
      return item;
    } catch (e) {
      saveError.value = firstErrorMessage(e) ?? '保存失败';
      throw e;
    } finally {
      saving.value = false;
    }
  }

  function setTab(next: AssetTab): void {
    tab.value = next;
  }

  function setStatusFilter(next: AssetStatusFilter): void {
    statusFilter.value = next;
  }

  /** 打开弹层时清掉上一次的保存错误。 */
  function clearSaveError(): void {
    saveError.value = '';
  }

  return {
    workspace,
    loading,
    loadError,
    saving,
    saveError,
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
    createAsset,
    updateAsset,
    setTab,
    setStatusFilter,
    clearSaveError,
  };
}
