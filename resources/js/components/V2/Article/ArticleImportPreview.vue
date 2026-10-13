<script setup lang="ts">
// AI 导入变更预览 —— 展示后端 preview 返回的 added / modified / unchanged / warnings。
// 本组件只消费合同，不执行任何解析。确认前绝不覆盖正式内容。
import { computed } from 'vue';
import type { ArticleImportPreview } from '../../../types/v2/article/article';

const props = defineProps<{ preview: ArticleImportPreview }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();

const hasChanges = computed(
  () => props.preview.added.length > 0 || props.preview.modified.length > 0,
);
const changeCount = computed(() => props.preview.added.length + props.preview.modified.length);
</script>

<template>
  <div class="rounded-lg border border-slate-200 bg-white" data-v2-article-diff>
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-900">变更预览</h3>
        <span
          class="rounded-full px-2.5 py-0.5 text-xs font-medium"
          :class="hasChanges ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'"
        >
          {{ hasChanges ? `共 ${changeCount} 处变更` : '无变更' }}
        </span>
      </div>
      <p class="mt-1 text-xs text-slate-400">
        以下为 AI 解析结果与当前内容的差异，确认前不会覆盖文章。
      </p>
    </div>

    <div class="space-y-3 px-4 py-3">
      <section v-if="preview.added.length">
        <h4 class="mb-1.5 text-xs font-semibold text-emerald-700">新增</h4>
        <div class="space-y-1.5">
          <div
            v-for="(a, i) in preview.added"
            :key="`add-${i}`"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
          >
            <span class="font-medium">{{ a.label }}</span>
            <p v-if="a.detail" class="mt-0.5 whitespace-pre-wrap text-emerald-700">{{ a.detail }}</p>
          </div>
        </div>
      </section>

      <section v-if="preview.modified.length">
        <h4 class="mb-1.5 text-xs font-semibold text-amber-700">修改</h4>
        <div class="space-y-2">
          <div
            v-for="(m, i) in preview.modified"
            :key="`mod-${m.field}-${i}`"
            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2"
          >
            <p class="text-sm font-medium text-amber-800">修改{{ m.label }}</p>
            <div class="mt-1 grid gap-2 sm:grid-cols-2">
              <div class="min-w-0">
                <p class="text-xs text-amber-500">修改前</p>
                <p class="whitespace-pre-wrap break-words text-sm text-slate-600 line-through decoration-slate-400">
                  {{ m.before ?? '（空）' }}
                </p>
              </div>
              <div class="min-w-0">
                <p class="text-xs text-amber-500">修改后</p>
                <p class="whitespace-pre-wrap break-words text-sm text-slate-800">{{ m.after ?? '（空）' }}</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section v-if="preview.unchanged.length">
        <h4 class="mb-1 text-xs font-semibold text-slate-400">未变化</h4>
        <p class="text-sm text-slate-500">{{ preview.unchanged.join(' / ') }}</p>
      </section>

      <section v-if="preview.warnings.length">
        <h4 class="mb-1 text-xs font-semibold text-rose-600">解析提醒</h4>
        <ul class="list-inside list-disc space-y-0.5 text-xs text-rose-600">
          <li v-for="(w, i) in preview.warnings" :key="`warn-${i}`">{{ w }}</li>
        </ul>
      </section>

      <p v-if="!hasChanges && !preview.warnings.length" class="text-sm text-slate-400">
        解析结果与当前内容一致，没有需要变更的字段。
      </p>
    </div>

    <footer class="flex items-center justify-end gap-3 border-t border-slate-100 px-4 py-3">
      <button
        type="button"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50"
        @click="emit('cancel')"
      >
        取消
      </button>
      <button
        type="button"
        :disabled="!hasChanges"
        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
        @click="emit('confirm')"
      >
        确认导入
      </button>
    </footer>
  </div>
</template>
