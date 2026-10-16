<script setup lang="ts">
// 人物 / 场景复用资产索引 —— 正式页面（Inertia 挂载；路由由 Work 集成接线）。
// 正式链路：
//   GET   /api/projects/{project}/reusable-assets
//   POST  /api/projects/{project}/reusable-assets
//   PATCH /api/projects/{project}/reusable-assets/{asset}
//
// P5-I02：页面的 [新增人物 / 新增场景] / [查看] / [准备建立] 全部是真实操作
// （打开新增 / 详情编辑弹层并写库），不再有 toast 占位。
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import ReusableAssetIndexBody from '../../../components/V2/ReusableAssets/ReusableAssetIndexBody.vue';
import ReusableAssetEditorDialog from '../../../components/V2/ReusableAssets/ReusableAssetEditorDialog.vue';
import ToastHost from '../../../components/ToastHost.vue';
import { useReusableAssetIndex } from '../../../composables/v2/reusableAssets/useReusableAssetIndex';
import {
  buildAssetCreatePayload,
  buildAssetUpdatePayload,
} from '../../../composables/v2/reusableAssets/assetIndexCore';
import type {
  AssetEditorForm,
  AssetTab,
  ReusableAssetItem,
  ReusableAssetType,
} from '../../../types/v2/reusableAssets/reusableAssets';
import { toast } from '../../../ui/toast';

const props = defineProps<{
  projectId: number | string;
}>();

const {
  loading,
  loadError,
  saving,
  saveError,
  tab,
  statusFilter,
  characterCounts,
  sceneCounts,
  tabCounts,
  visibleItems,
  isEmpty,
  load,
  createAsset,
  updateAsset,
  setTab,
  setStatusFilter,
  clearSaveError,
} = useReusableAssetIndex(() => props.projectId);

onMounted(() => {
  void load();
});

// ── 新增 / 详情编辑弹层 ─────────────────────────────────────────
const dialogOpen = ref(false);
const dialogMode = ref<'create' | 'edit'>('create');
const dialogType = ref<ReusableAssetType>('character');
const dialogItem = ref<ReusableAssetItem | null>(null);

function openCreate(type: AssetTab): void {
  clearSaveError();
  dialogMode.value = 'create';
  dialogType.value = type;
  dialogItem.value = null;
  dialogOpen.value = true;
}

function openDetail(item: ReusableAssetItem): void {
  clearSaveError();
  dialogMode.value = 'edit';
  dialogType.value = item.type;
  dialogItem.value = item;
  dialogOpen.value = true;
}

function closeDialog(): void {
  dialogOpen.value = false;
}

async function onSubmit(form: AssetEditorForm): Promise<void> {
  try {
    if (dialogMode.value === 'create') {
      await createAsset(buildAssetCreatePayload(dialogType.value, form));
      toast.success('已新增，可继续补充预览图与说明');
    } else if (dialogItem.value) {
      await updateAsset(dialogItem.value.id, buildAssetUpdatePayload(form));
      toast.success('已保存');
    }
    dialogOpen.value = false;
  } catch {
    // 保存失败：错误经 saveError 显示在弹层内，弹层保持打开以便重试。
  }
}

function onBack(): void {
  if (window.history.length > 1) window.history.back();
  else toast.info('待集成项目工作台导航');
}
</script>

<template>
  <Head title="人物 / 场景复用索引" />
  <div class="mx-auto max-w-6xl px-4 py-6">
    <ReusableAssetIndexBody
      :loading="loading"
      :load-error="loadError"
      :tab="tab"
      :status-filter="statusFilter"
      :character-counts="characterCounts"
      :scene-counts="sceneCounts"
      :tab-counts="tabCounts"
      :visible-items="visibleItems"
      :is-empty="isEmpty"
      @back="onBack"
      @switch-tab="setTab"
      @change-filter="setStatusFilter"
      @retry="load"
      @create="openCreate"
      @prepare-character="openDetail"
      @prepare-scene="openDetail"
      @view-character="openDetail"
      @view-scene="openDetail"
    />

    <ReusableAssetEditorDialog
      :open="dialogOpen"
      :mode="dialogMode"
      :type="dialogType"
      :item="dialogItem"
      :saving="saving"
      :error="saveError"
      @close="closeDialog"
      @submit="onSubmit"
    />
  </div>
  <!-- 正式页面自身挂载 ToastHost（DEV Preview 由 DevPreviewApp 挂载，正式页不经过它）。 -->
  <ToastHost />
</template>
