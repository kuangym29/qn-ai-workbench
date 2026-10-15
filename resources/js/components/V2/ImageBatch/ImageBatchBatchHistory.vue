<script setup lang="ts">
// V2 图片批次 —— 批次历史（轻量入口：创建时间 / 包含页面 / 状态 / 说明摘要）。
// 不做复杂版本管理页面；系统未来自动保留真实 AI task snapshot。
import type { BatchHistoryItem } from '../../../types/v2/imageBatch/imageBatch';
import { historyStatusLabel } from '../../../composables/v2/imageBatch/imageBatchCore';

defineProps<{ history: BatchHistoryItem[]; open: boolean }>();

const emit = defineEmits<{ toggle: [] }>();

function formatTime(isoStr: string | null): string {
  if (!isoStr) return '时间未记录';
  const d = new Date(isoStr);
  if (Number.isNaN(d.getTime())) return isoStr;
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}/${p(d.getMonth() + 1)}/${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}
</script>

<template>
  <section class="rounded-lg border border-slate-200 bg-white p-4" data-v2-image-batch-history>
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold text-slate-900">批次历史</h2>
      <button
        type="button"
        class="text-xs text-slate-400 underline hover:text-slate-600"
        @click="emit('toggle')"
      >
        {{ open ? '收起历史批次' : `查看历史批次（${history.length}）` }}
      </button>
    </div>
    <div v-if="open" class="mt-3 space-y-2">
      <div
        v-for="item in history"
        :key="String(item.id)"
        class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2 text-sm"
      >
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium text-slate-700">{{ item.page_labels.join('、') }}</span>
          <span class="rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-600">
            {{ historyStatusLabel(item.status) }}
          </span>
          <span class="text-xs text-slate-400">{{ formatTime(item.created_at) }}</span>
        </div>
        <p v-if="item.instruction_summary" class="mt-1 text-xs text-slate-500">{{ item.instruction_summary }}</p>
      </div>
      <p v-if="history.length === 0" class="text-sm text-slate-400">暂无历史批次。</p>
    </div>
  </section>
</template>
