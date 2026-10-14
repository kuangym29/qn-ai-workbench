<script setup lang="ts">
// V2-P3-C06 独立开发预览 App（DEV ONLY）—— 挂载人物 / 场景复用索引。
// 不依赖 Laravel / Inertia / 后端 API；通过注入 DEV mock client 模拟：
//   GET（内存）→ 人物 / 场景分组 → Tab / 筛选。
// [准备建立] 只 emit（本页 toast 占位）；不调用 Work、不创建生成 API。
//
// 正式 composable（useReusableAssetIndex）0 引用本 DEV Mock；Mock 仅在此处注入。
import { onMounted } from 'vue';
import { useReusableAssetIndex } from '../../../composables/v2/reusableAssets/useReusableAssetIndex';
import { createMockAssetIndexClient } from '../../../composables/v2/reusableAssets/assetIndexDevMock';
import ReusableAssetIndexBody from './ReusableAssetIndexBody.vue';
import ToastHost from '../../ToastHost.vue';
import { toast } from '../../../ui/toast';
import type { ReusableAssetItem } from '../../../types/v2/reusableAssets/reusableAssets';

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

function onPrepare(item: ReusableAssetItem) {
  toast.info(`[DEV] 准备建立 ${item.name} 的稳定母版：真实流程由后续 Work 提供（待集成）。`);
}

function onView(item: ReusableAssetItem) {
  toast.info(`[DEV] 查看 ${item.name}：详情页待集成。`);
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-700 [overflow-wrap:anywhere]">
      DEV PREVIEW：固定示例数据（内存 Mock client，仅 GET）；不请求后端。
      正式数据由 WorkBuddy 后端按合同（GET /api/projects/{project}/reusable-assets）接线后提供。
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
      @prepare-character="onPrepare"
      @prepare-scene="onPrepare"
      @view-character="onView"
      @view-scene="onView"
    />

    <ToastHost />
  </div>
</template>
