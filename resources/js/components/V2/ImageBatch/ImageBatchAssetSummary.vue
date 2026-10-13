<script setup lang="ts">
// V2 图片批次 —— 批次资产检查摘要（整批聚合一次，不做逐页重复提醒，不做复杂 DAM）。
import type { AssetCheck } from '../../../types/v2/imageBatch/imageBatch';
import { assetStatusLabel } from '../../../composables/v2/imageBatch/imageBatchCore';

defineProps<{ assets: AssetCheck[] }>();

const statusMark: Record<string, string> = {
  existing: 'bg-emerald-500',
  missing: 'bg-rose-500',
  new_in_batch: 'bg-sky-500',
};
</script>

<template>
  <section class="rounded-lg border border-slate-200 bg-white p-4" data-v2-image-batch-assets>
    <h2 class="text-sm font-semibold text-slate-900">批次资产检查</h2>
    <p class="mt-0.5 text-xs text-slate-400">整批聚合一次：已有 / 缺失 / 本批新建。</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
      <div v-if="assets.some((a) => a.type === 'character')">
        <p class="mb-1 text-xs font-medium text-slate-500">人物</p>
        <ul class="space-y-1">
          <li v-for="a in assets.filter((x) => x.type === 'character')" :key="'c-' + a.name" class="flex items-center gap-2 text-sm text-slate-700">
            <span class="h-2 w-2 rounded-full" :class="statusMark[a.status] ?? 'bg-slate-300'" />
            <span>{{ a.name }}</span>
            <span class="text-xs text-slate-400">{{ assetStatusLabel(a.status) }}</span>
          </li>
        </ul>
      </div>
      <div v-if="assets.some((a) => a.type === 'scene')">
        <p class="mb-1 text-xs font-medium text-slate-500">场景</p>
        <ul class="space-y-1">
          <li v-for="a in assets.filter((x) => x.type === 'scene')" :key="'s-' + a.name" class="flex items-center gap-2 text-sm text-slate-700">
            <span class="h-2 w-2 rounded-full" :class="statusMark[a.status] ?? 'bg-slate-300'" />
            <span>{{ a.name }}</span>
            <span class="text-xs text-slate-400">{{ assetStatusLabel(a.status) }}</span>
          </li>
        </ul>
      </div>
      <p v-if="assets.length === 0" class="text-sm text-slate-400">暂无资产检查数据。</p>
    </div>
  </section>
</template>
