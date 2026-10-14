<script setup lang="ts">
// V2「待我审核」轻量审核中心 —— 待审核列表（简单列表，不做 Kanban）。
// 每行一眼可判断：内容名称 / 渠道·形式 / 审核对象 / 当前版本 / 提交时间。
import type { ReviewItem } from '../../../types/v2/review/review';
import { channelLabel, formatLabel, truncateSummary } from '../../../composables/v2/review/reviewCore';
import Badge from '../../Badge.vue';
import LoadingState from '../../LoadingState.vue';
import ErrorState from '../../ErrorState.vue';

defineProps<{
  items: ReviewItem[];
  loading: boolean;
  loadError: string;
  selectedId: number | null;
}>();

const emit = defineEmits<{
  open: [id: number];
  retry: [];
}>();
</script>

<template>
  <div>
    <LoadingState v-if="loading" label="加载待审核列表…" />

    <ErrorState
      v-else-if="loadError"
      title="加载待审核列表失败"
      :message="loadError"
    >
      <template #action>
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          @click="emit('retry')"
        >
          重试
        </button>
      </template>
    </ErrorState>

    <div
      v-else-if="items.length === 0"
      class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center"
    >
      <div
        class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-500"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
      </div>
      <p class="text-sm font-medium text-slate-700">当前没有待审核内容</p>
      <p class="mt-1 text-sm text-slate-500">新提交的审核项会自动出现在这里。</p>
    </div>

    <ul v-else class="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200 bg-white">
      <li v-for="item in items" :key="item.id">
        <button
          type="button"
          class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-slate-50"
          :class="{ 'bg-slate-50 ring-1 ring-inset ring-slate-200': item.id === selectedId }"
          @click="emit('open', item.id)"
        >
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span class="truncate text-sm font-semibold text-slate-800">{{ item.content_title }}</span>
              <Badge variant="active">{{ item.review_target_label }}</Badge>
            </div>
            <p class="mt-1 text-xs text-slate-500">
              {{ channelLabel(item.channel) }} · {{ formatLabel(item.format) }}
            </p>
            <p v-if="item.preview_summary" class="mt-1 truncate text-xs text-slate-400">
              {{ truncateSummary(item.preview_summary, 90) }}
            </p>
          </div>
          <div class="shrink-0 text-right">
            <p class="text-xs font-medium text-slate-600">版本 {{ item.version_label }}</p>
            <p class="mt-0.5 text-xs text-slate-400">{{ new Date(item.submitted_at).toLocaleString('zh-CN') }}</p>
          </div>
        </button>
      </li>
    </ul>
  </div>
</template>
