<script setup lang="ts">
// V2 图片文章编辑器主体 —— 纯展示 + 局部交互（不依赖 Inertia / API）。
// 正式编辑页使用的展示组件。
//
// 布局：
//   顶部（文章名称 / 栏目·公众号·图片文章 / 来源 / 核心表达 / 整篇故事线）
//   → AI 导入（主入口）
//   → 页面卡列表（每页 8 项信息，快速浏览）
//   → 保存
import { computed } from 'vue';
import type {
  ImagePostContentItem,
  ImagePostImportPreview,
  ImagePostPage,
  ImagePostSource,
  ImagePostWarning,
} from '../../types/v2/imagePost';
import { PAGE_TYPE_LABELS } from '../../api/types';
import Badge from '../Badge.vue';
import SourceTrail from '../SourceTrail.vue';
import LoadingState from '../LoadingState.vue';
import ErrorState from '../ErrorState.vue';
import ImagePostAiImport from './ImagePostAiImport.vue';
import ImagePostPageCard from './ImagePostPageCard.vue';

const props = defineProps<{
  item: ImagePostContentItem | null;
  pages: ImagePostPage[];
  loading: boolean;
  loadError: string;
  saving: boolean;
  applying: boolean;
  applyError: string;
  warnings: ImagePostWarning[];
  columnName: string;
  source: ImagePostSource;
  // AI 导入受控状态
  phase: 'idle' | 'parsing' | 'preview' | 'error';
  pasteText: string;
  parseError: string;
  preview: ImagePostImportPreview | null;
  appliedCount: number;
}>();
const emit = defineEmits<{
  'update:pasteText': [value: string];
  parse: [];
  confirmImport: [];
  cancelImport: [];
  save: [];
  updatePage: [page: ImagePostPage];
  updateCoreMessage: [value: string];
  updateStoryLine: [value: string];
  back: [];
}>();

const pageTypeCounts = computed(() => {
  const counts: Record<string, number> = {};
  for (const p of props.pages) {
    counts[p.page_type] = (counts[p.page_type] ?? 0) + 1;
  }
  return counts;
});

function pageTypeLabel(t: string): string {
  return PAGE_TYPE_LABELS[t as keyof typeof PAGE_TYPE_LABELS] ?? t;
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-6">
    <!-- 顶部：文章名称 / 栏目渠道 / 来源 / 整篇编辑信息 -->
    <header class="rounded-lg border border-slate-200 bg-white p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold text-slate-900">
              {{ item?.title ?? '公众号图片文章' }}
            </h1>
            <Badge variant="planning" label="小绿书" />
          </div>
          <p class="mt-1 text-sm text-slate-500">
            {{ columnName }} · 公众号·图片文章
          </p>
          <SourceTrail :inspiration="source.inspiration" :topic="source.topic" />
        </div>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
          @click="emit('back')"
        >
          返回
        </button>
      </div>

      <!-- 整篇编辑信息：核心表达 + 故事线 -->
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div>
          <label for="image-post-core" class="block text-xs font-medium text-slate-500">核心表达</label>
          <textarea
            id="image-post-core"
            :disabled="loading || saving || applying || phase === 'preview' || phase === 'parsing'"
            :value="item?.core_message ?? ''"
            rows="2"
            class="mt-1 w-full resize-y rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-slate-400 focus:bg-white focus:outline-none"
            placeholder="整篇核心表达"
            @input="emit('updateCoreMessage', ($event.target as HTMLTextAreaElement).value)"
          />
        </div>
        <div>
          <label for="image-post-story" class="block text-xs font-medium text-slate-500">整篇故事线</label>
          <textarea
            id="image-post-story"
            :disabled="loading || saving || applying || phase === 'preview' || phase === 'parsing'"
            :value="item?.story_line ?? ''"
            rows="2"
            class="mt-1 w-full resize-y rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-slate-400 focus:bg-white focus:outline-none"
            placeholder="整篇故事线"
            @input="emit('updateStoryLine', ($event.target as HTMLTextAreaElement).value)"
          />
        </div>
      </div>
      <p class="mt-2 text-xs text-slate-400">
        共 {{ pages.length }} 页
        <template v-for="(count, type) in pageTypeCounts" :key="type">
          · {{ pageTypeLabel(type) }} {{ count }}
        </template>
      </p>
    </header>

    <!-- 加载 / 错误 -->
    <LoadingState v-if="loading" label="加载图片文章…" />
    <ErrorState
      v-else-if="loadError"
      title="加载失败"
      :message="loadError"
    >
      <template #action>
        <button
          type="button"
          class="rounded-md border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-100"
          @click="emit('back')"
        >
          返回
        </button>
      </template>
    </ErrorState>

    <template v-else>
      <!-- AI 导入（主入口） -->
      <ImagePostAiImport
        :model-value="pasteText"
        :phase="phase"
        :parse-error="parseError"
        :preview="preview"
        :applying="applying"
        :busy="saving || applying"
        :apply-error="applyError"
        @update:model-value="emit('update:pasteText', $event)"
        @parse="emit('parse')"
        @confirm="emit('confirmImport')"
        @cancel="emit('cancelImport')"
      />

      <!-- 导入完成提示 -->
      <p v-if="appliedCount > 0" role="status" class="text-xs text-emerald-700">
        已应用 AI 导入的 {{ appliedCount }} 处变更，已保存到服务端。
      </p>

      <!-- 页面卡列表 -->
      <fieldset :disabled="saving || applying || phase === 'preview' || phase === 'parsing'" aria-label="页面列表">
        <div class="mb-2 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-900">页面</h2>
          <span class="text-xs text-slate-400">点击卡片「编辑」修改单页</span>
        </div>
        <div v-if="pages.length" class="grid gap-3 lg:grid-cols-2" data-v2-image-post-pages>
          <ImagePostPageCard
            v-for="page in pages"
            :key="page.page_no"
            :page="page"
            @select="() => {}"
            @update="emit('updatePage', $event)"
          />
        </div>
        <div
          v-else
          class="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center"
        >
          <p class="text-sm font-medium text-slate-600">还没有页面</p>
          <p class="mt-1 text-sm text-slate-400">使用上方 AI 导入粘贴整篇内容，快速生成页面。</p>
        </div>
      </fieldset>

      <p v-for="(warning, i) in warnings" :key="i" role="status" class="text-xs text-amber-700">{{ warning.message }}</p>
      <!-- 保存 -->
      <footer
        class="sticky bottom-0 flex items-center justify-end gap-3 rounded-lg border border-slate-200 bg-white/95 px-4 py-3 backdrop-blur"
      >

        <button
          type="button"
          :disabled="saving || applying || phase === 'preview' || phase === 'parsing'"
          class="rounded-md bg-slate-900 px-5 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
          @click="emit('save')"
        >
          {{ saving ? '保存中…' : '保存整篇' }}
        </button>
      </footer>
    </template>
  </div>
</template>
