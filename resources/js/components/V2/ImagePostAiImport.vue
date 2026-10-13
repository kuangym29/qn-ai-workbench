<script setup lang="ts">
// AI 粘贴导入入口 —— 编辑器的主要录入方式。
// 流程：[粘贴 AI 内容] → 解析 → loading/error → 变更预览 → 确认/取消。
// 支持局部更新：只粘贴某一页内容时，预览仅显示受影响页面。
import type { ImagePostImportPreview } from '../../types/v2/imagePost';
import ImagePostDiffPreview from './ImagePostDiffPreview.vue';

defineProps<{
  /** 当前粘贴文本（受控，由父级持有） */
  modelValue: string;
  /** idle | parsing | preview | error */
  phase: 'idle' | 'parsing' | 'preview' | 'error';
  parseError: string;
  preview: ImagePostImportPreview | null;
  applying: boolean;
  busy: boolean;
  applyError: string;
}>();
const emit = defineEmits<{
  'update:modelValue': [value: string];
  parse: [];
  confirm: [];
  cancel: [];
}>();
</script>

<template>
  <section class="rounded-lg border border-slate-200 bg-white" data-v2-image-post-ai-import>
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-900">AI 导入</h3>
        <span class="text-xs text-slate-400">粘贴整段结果，或只粘贴某一页内容</span>
      </div>
      <p class="mt-1 text-xs text-slate-400">
        用 --- 分隔每页，填写「页码: 1」「页面类型: 正文」「最终文字: …」。整篇字段放在首个分隔符前；先预览，确认后才写入。
      </p>
    </div>

    <!-- 输入区（preview 阶段隐藏输入，聚焦预览） -->
    <div v-if="phase !== 'preview'" class="space-y-3 px-4 py-3">
      <textarea
        aria-label="AI 内容"
        :disabled="busy || phase === 'parsing'"
        :value="modelValue"
        rows="7"
        class="w-full resize-y rounded-md border border-slate-300 px-3 py-2 font-mono text-sm focus:border-slate-400 focus:outline-none"
        placeholder="在这里粘贴 AI 生成的内容结果…"
        @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
      />
      <p v-if="phase === 'error' && parseError" role="alert" class="text-xs text-rose-600">
        {{ parseError }}
      </p>
      <button
        type="button"
        :disabled="busy || phase === 'parsing' || !modelValue.trim()"
        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
        @click="emit('parse')"
      >
        {{ phase === 'parsing' ? '解析中…' : '解析并预览' }}
      </button>
      <p v-if="phase === 'parsing'" role="status" class="text-xs text-slate-400">
        正在解析粘贴内容…
      </p>
    </div>

    <p v-if="applyError" role="alert" class="px-4 py-2 text-sm text-rose-600">{{ applyError }}</p>
    <!-- 解析成功：预览阶段 -->
    <ImagePostDiffPreview
      v-if="phase === 'preview' && preview"
      :preview="preview"
      :applying="applying"
      @confirm="emit('confirm')"
      @cancel="emit('cancel')"
    />
  </section>
</template>
