<script setup lang="ts">
// 复用资产单卡（人物固定身份 / 场景稳定母版共用）。
// 缩略图辅助、不抢业务信息；缺失状态克制显示，操作只有 [查看] / [准备建立]。
import type { ReusableAssetItem } from '../../../types/v2/reusableAssets/reusableAssets';
import { formatLastUsed, statusLabel } from '../../../composables/v2/reusableAssets/assetIndexCore';

defineProps<{ item: ReusableAssetItem }>();
const emit = defineEmits<{
  view: [item: ReusableAssetItem];
  prepare: [item: ReusableAssetItem];
}>();

const typeLabel: Record<ReusableAssetItem['type'], string> = {
  character: '人物',
  scene: '场景',
};
</script>

<template>
  <div
    class="flex gap-3 rounded-lg border bg-white p-4"
    :class="item.status === 'missing' ? 'border-amber-200' : 'border-slate-200'"
    data-v2-reusable-asset
  >
    <!-- 缩略图（辅助）；无图显示占位 -->
    <div class="shrink-0">
      <img
        v-if="item.thumbnail_url"
        :src="item.thumbnail_url"
        :alt="item.name"
        class="h-12 w-12 rounded-lg border border-slate-200 object-cover"
      />
      <div
        v-else
        class="flex h-12 w-12 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-slate-300"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"
          />
        </svg>
      </div>
    </div>

    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <p class="truncate text-sm font-semibold text-slate-800" :title="item.name">{{ item.name }}</p>
        <span
          class="shrink-0 rounded-full px-2 py-0.5 text-xs"
          :class="
            item.status === 'existing'
              ? 'bg-emerald-50 text-emerald-700'
              : 'bg-amber-50 text-amber-700'
          "
          >{{ statusLabel(item.status) }}</span
        >
      </div>

      <p v-if="item.description" class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
        {{ item.description }}
      </p>
      <p v-else class="mt-1 text-xs text-slate-400">暂无说明</p>

      <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <span class="text-xs text-slate-400">
          {{ typeLabel[item.type] }} · 最近使用：{{ formatLastUsed(item.last_used_at) }}
        </span>
        <span class="flex w-full flex-wrap justify-end gap-2 sm:w-auto sm:shrink-0">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50"
            @click="emit('view', item)"
          >
            查看
          </button>
          <button
            v-if="item.status === 'missing'"
            type="button"
            class="rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs text-amber-800 hover:bg-amber-100"
            data-v2-prepare-button
            @click="emit('prepare', item)"
          >
            准备建立
          </button>
        </span>
      </div>
    </div>
  </div>
</template>
