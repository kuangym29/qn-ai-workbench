<script setup lang="ts">
// V2-P3-C06 独立开发预览 App（DEV ONLY）—— 挂载人物 / 场景复用索引。
// 不依赖 Laravel / Inertia / 后端 API；通过注入 DEV mock client 模拟：
//   GET（内存）→ 人物 / 场景分组 → Tab / 筛选
//   POST / PATCH（内存）→ 新增 / 编辑（P5-I02）
//
// 正式 composable（useReusableAssetIndex）0 引用本 DEV Mock；Mock 仅在此处注入。
// 正式页面 resources/js/pages/V2/ReusableAssets/ReusableAssetIndex.vue 走真实后端 API。
import { onMounted, ref } from 'vue';
import { useReusableAssetIndex } from '../../../composables/v2/reusableAssets/useReusableAssetIndex';
import { createMockAssetIndexClient } from '../../../composables/v2/reusableAssets/assetIndexDevMock';
import ReusableAssetIndexBody from './ReusableAssetIndexBody.vue';
import ReusableAssetEditorDialog from './ReusableAssetEditorDialog.vue';
import ToastHost from '../../ToastHost.vue';
import { toast } from '../../../ui/toast';
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

// DEV 注入：projectId + 内存 mock client。
// ?empty=1 用于浏览器验收空状态（空列表 / 筛选无结果）。
const initial = new URLSearchParams(window.location.search).get('empty')
  ? { characters: [], scenes: [] }
  : undefined;
const mock = createMockAssetIndexClient(initial);
const index = useReusableAssetIndex(1, mock.client);

onMounted(async () => {
  await index.load();
});

function onBack() {
  toast.info('[DEV] 返回导航待集成');
}

const dialogOpen = ref(false);
const dialogMode = ref<'create' | 'edit'>('create');
const dialogType = ref<ReusableAssetType>('character');
const dialogItem = ref<ReusableAssetItem | null>(null);

function openCreate(type: AssetTab) {
  index.clearSaveError();
  dialogMode.value = 'create';
  dialogType.value = type;
  dialogItem.value = null;
  dialogOpen.value = true;
}

function openDetail(item: ReusableAssetItem) {
  index.clearSaveError();
  dialogMode.value = 'edit';
  dialogType.value = item.type;
  dialogItem.value = item;
  dialogOpen.value = true;
}

async function onSubmit(form: AssetEditorForm) {
  try {
    if (dialogMode.value === 'create') {
      await index.createAsset(buildAssetCreatePayload(dialogType.value, form));
    } else if (dialogItem.value) {
      await index.updateAsset(dialogItem.value.id, buildAssetUpdatePayload(form));
    }
    dialogOpen.value = false;
    toast.success('[DEV] 已保存（内存 Mock）');
  } catch {
    // 错误经 saveError 显示在弹层内。
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-700 [overflow-wrap:anywhere]">
      DEV PREVIEW：固定示例数据（内存 Mock client，GET / POST / PATCH）；不请求后端。
      正式数据由 WorkBuddy 后端按合同（GET/POST/PATCH /api/projects/{project}/reusable-assets）接线后提供。
    </div>

    <ReusableAssetIndexBody
      :loading="index.loading.value"
      :load-error="index.loadError.value"
      :tab="index.tab.value"
      :status-filter="index.statusFilter.value"
      :character-counts="index.characterCounts.value"
      :scene-counts="index.sceneCounts.value"
      :tab-counts="index.tabCounts.value"
      :visible-items="index.visibleItems.value"
      :is-empty="index.isEmpty.value"
      @back="onBack"
      @switch-tab="index.setTab"
      @change-filter="index.setStatusFilter"
      @retry="index.load"
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
      :saving="index.saving.value"
      :error="index.saveError.value"
      @close="dialogOpen = false"
      @submit="onSubmit"
    />

    <ToastHost />
  </div>
</template>
