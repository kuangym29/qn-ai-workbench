<script setup lang="ts">
// AI 粘贴导入入口 —— 图文文章的主要录入方式。
// 本组件只负责：粘贴区 / parsing / error / preview 展示 / Confirm / Cancel。
// 解析由后端 preview 完成；前端不实现解析业务逻辑。
import type { ArticleImportPreview } from '../../../types/v2/article/article';
import ArticleImportPreviewView from './ArticleImportPreview.vue';

defineProps<{
  modelValue: string;
  phase: 'idle' | 'parsing' | 'preview' | 'error';
  parseError: string;
  preview: ArticleImportPreview | null;
  disabled: boolean;
}>();
const emit = defineEmits<{
  'update:modelValue': [value: string];
  parse: [];
  confirm: [];
  cancel: [];
}>();
</script>

<template>
  <section class="rounded-lg border border-slate-200 bg-white" data-v2-article-ai-import>
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-900">AI 导入</h3>
        <span class="text-xs text-slate-400">粘贴 AI 生成的文章内容</span>
      </div>
      <p class="mt-1 text-xs text-slate-400">
        提交原文后由系统解析，先预览差异，确认后才写入文章。
      </p>
    </div>

    <div v-if="phase !== 'preview'" class="space-y-3 px-4 py-3">
      <textarea
        :disabled="disabled"
        :value="modelValue"
        rows="8"
        class="w-full resize-y rounded-md border border-slate-300 px-3 py-2 font-mono text-sm focus:border-slate-400 focus:outline-none"
        placeholder="在这里粘贴 AI 生成的文章结果…"
        @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
      />
      <p v-if="phase === 'error' && parseError" role="alert" class="text-xs text-rose-600">
        {{ parseError }}
      </p>
      <button
        type="button"
        :disabled="disabled || !modelValue.trim()"
        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
        @click="emit('parse')"
      >
        {{ phase === 'parsing' ? '解析中…' : '解析并预览' }}
      </button>
      <p v-if="phase === 'parsing'" role="status" class="text-xs text-slate-400">
        正在请求系统解析粘贴内容…
      </p>
    </div>

    <ArticleImportPreviewView
      v-if="phase === 'preview' && preview"
      :preview="preview"
      @confirm="emit('confirm')"
      @cancel="emit('cancel')"
    />
  </section>
</template>
