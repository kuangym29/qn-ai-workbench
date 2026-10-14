<script setup lang="ts">
// 配图建议编辑 —— 支持：
//   - 无配图（空态）
//   - 封面建议（主区域单独编辑，本组件只负责「文中配图建议」）
//   - 文中某位置配图建议（position 可选数字）
// 本任务不做 MediaBatch / 批量生图流程。
import type { ArticleImageSuggestion } from '../../../types/v2/article/article';

const props = defineProps<{
  suggestions: ArticleImageSuggestion[] | null;
}>();
const emit = defineEmits<{
  update: [list: ArticleImageSuggestion[] | null];
  add: [];
  remove: [index: number];
  updateItem: [index: number, patch: Partial<ArticleImageSuggestion>];
}>();

function draftPosition(v: string): number | null {
  const n = Number(v);
  return Number.isInteger(n) && n >= 1 ? n : null;
}
</script>

<template>
  <div class="space-y-2" data-v2-article-images>
    <template v-if="suggestions && suggestions.length">
      <div
        v-for="(s, i) in suggestions"
        :key="i"
        class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2"
      >
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs font-medium text-slate-500">配图建议 #{{ i + 1 }}</span>
          <button
            type="button"
            class="text-xs text-slate-400 underline hover:text-rose-600"
            @click="emit('remove', i)"
          >
            移除
          </button>
        </div>
        <div class="mt-1.5 grid gap-2 sm:grid-cols-[7rem_1fr]">
          <input
            :value="s.position ?? ''"
            type="text"
            inputmode="numeric"
            class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-slate-400 focus:outline-none"
            placeholder="位置（第N段）"
            @input="emit('updateItem', i, { position: draftPosition(($event.target as HTMLInputElement).value) })"
          />
          <textarea
            :value="s.description"
            rows="2"
            class="w-full resize-y rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-slate-400 focus:outline-none"
            placeholder="配图内容建议"
            @input="emit('updateItem', i, { description: ($event.target as HTMLTextAreaElement).value })"
          />
        </div>
      </div>
    </template>
    <p v-else class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-400">
      暂无配图建议（本文章不配图）。
    </p>
    <button
      type="button"
      class="rounded-md border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:border-slate-400 hover:bg-slate-50"
      @click="emit('add')"
    >
      + 添加文中配图建议
    </button>
  </div>
</template>
