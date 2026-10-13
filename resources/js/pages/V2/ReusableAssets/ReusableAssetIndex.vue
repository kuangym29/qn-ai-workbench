<script setup lang="ts">
// 人物 / 场景复用资产索引 —— 正式页面（Inertia 挂载；路由由 Work 集成接线）。
// 正式链路：GET /api/projects/{project}/reusable-assets
// 第一阶段 [准备建立] / [查看] 仅 toast 占位（准备建立为组件 emit 契约）。
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import ReusableAssetIndexBody from '../../../components/V2/ReusableAssets/ReusableAssetIndexBody.vue';
import { useReusableAssetIndex } from '../../../composables/v2/reusableAssets/useReusableAssetIndex';
import type { ReusableAssetItem } from '../../../types/v2/reusableAssets/reusableAssets';
import { toast } from '../../../ui/toast';

const props = defineProps<{
  projectId: number | string;
}>();

const {
  loading,
  loadError,
  tab,
  statusFilter,
  characterCounts,
  sceneCounts,
  tabCounts,
  visibleItems,
  isEmpty,
  load,
  setTab,
  setStatusFilter,
} = useReusableAssetIndex(props.projectId);

onMounted(load);

function onBack() {
  if (window.history.length > 1) window.history.back();
  else toast.info('待集成项目工作台导航');
}

function prepareLabel(item: ReusableAssetItem): string {
  return `准备建立 ${item.name} 的稳定母版（待 Work 集成）`;
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
      @prepare-character="(item) => toast.info(prepareLabel(item))"
      @prepare-scene="(item) => toast.info(prepareLabel(item))"
      @view-character="(item) => toast.info(`查看人物 ${item.name}（详情待集成）`)"
      @view-scene="(item) => toast.info(`查看场景 ${item.name}（详情待集成）`)"
    />
  </div>
</template>
