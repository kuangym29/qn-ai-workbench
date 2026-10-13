<script setup lang="ts">
// V2 公众号图文文章编辑器主体 —— 纯展示 + 局部交互（不依赖 Inertia / API）。
// 正式页面（pages/V2/Article/ArticleEditor.vue）与独立开发预览共用本组件。
//
// 布局（克制，不做重型 CMS）：
//   顶部（文章名称 / 栏目·公众号·图文文章 / 来源 › / 共享信息弱展示）
//   → AI 导入（主入口）
//   → 标题 / 摘要 / 正文 / 配图建议 / 封面建议
//   → 制作子项轻量预留
//   → 保存
import type {
  ArticleContentItem,
  ArticleFields,
  ArticleImageSuggestion,
  ArticleImportPreview,
} from '../../../types/v2/article/article';
import { ARTICLE_FIELD_LABELS } from '../../../composables/v2/article/articleCore';
import Badge from '../../Badge.vue';
import LoadingState from '../../LoadingState.vue';
import ErrorState from '../../ErrorState.vue';
import ArticleAiImport from './ArticleAiImport.vue';
import ArticleImageSuggestions from './ArticleImageSuggestions.vue';

const props = defineProps<{
  item: ArticleContentItem | null;
  fields: ArticleFields;
  loading: boolean;
  loadError: string;
  saving: boolean;
  phase: 'idle' | 'parsing' | 'preview' | 'error';
  pasteText: string;
  parseError: string;
  preview: ArticleImportPreview | null;
}>();
const emit = defineEmits<{
  'update:pasteText': [value: string];
  parse: [];
  confirmImport: [];
  cancelImport: [];
  save: [];
  updateField: [key: keyof ArticleFields, value: string];
  addImage: [];
  removeImage: [index: number];
  updateImage: [index: number, patch: Partial<ArticleImageSuggestion>];
  back: [];
}>();

const IMAGE_FIELD_LABELS = ARTICLE_FIELD_LABELS;
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <!-- 顶部：文章名称 / 栏目渠道 / 来源 / 共享信息弱展示 -->
    <header class="rounded-lg border border-slate-200 bg-white p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold text-slate-900">
              {{ item?.title ?? '公众号图文文章' }}
            </h1>
            <Badge variant="planning" label="图文文章" />
          </div>
          <p class="mt-1 text-sm text-slate-500">
            {{ item?.column_name ?? '栏目' }} · 公众号·图文文章
          </p>
          <p v-if="item?.column_slug" class="mt-0.5 text-xs text-slate-400">
            来源 › {{ item.column_slug }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
          @click="emit('back')"
        >
          返回
        </button>
      </div>

      <!-- 共享信息弱展示（继承自内容篇目，不要求重复录入） -->
      <details class="mt-4 rounded-md bg-slate-50 px-3 py-2">
        <summary class="cursor-pointer text-xs font-medium text-slate-500">
          共享信息（来自内容篇目）
        </summary>
        <div class="mt-2 space-y-1.5 text-xs text-slate-600">
          <p v-if="item?.core_message"><span class="text-slate-400">核心表达：</span>{{ item.core_message }}</p>
          <p v-else class="text-slate-400">核心表达：（未填写）</p>
          <p v-if="item?.story_line"><span class="text-slate-400">整篇故事线：</span>{{ item.story_line }}</p>
          <p v-else class="text-slate-400">整篇故事线：（未填写）</p>
        </div>
      </details>
    </header>

    <!-- 加载 / 错误 -->
    <LoadingState v-if="loading" label="加载图文文章…" />
    <ErrorState v-else-if="loadError" title="加载失败" :message="loadError">
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
      <ArticleAiImport
        :model-value="pasteText"
        :phase="phase"
        :parse-error="parseError"
        :preview="preview"
        @update:model-value="emit('update:pasteText', $event)"
        @parse="emit('parse')"
        @confirm="emit('confirmImport')"
        @cancel="emit('cancelImport')"
      />

      <!-- 人工编辑（修订入口） -->
      <section class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">文章内容</h2>

        <div>
          <label class="block text-xs font-medium text-slate-500">{{ IMAGE_FIELD_LABELS.headline }}</label>
          <input
            :value="fields.headline ?? ''"
            type="text"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base font-medium focus:border-slate-400 focus:outline-none"
            placeholder="文章标题"
            @input="emit('updateField', 'headline', ($event.target as HTMLInputElement).value)"
          />
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-500">{{ IMAGE_FIELD_LABELS.summary }}</label>
          <textarea
            :value="fields.summary ?? ''"
            rows="2"
            class="mt-1 w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            placeholder="文章摘要"
            @input="emit('updateField', 'summary', ($event.target as HTMLTextAreaElement).value)"
          />
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-500">{{ IMAGE_FIELD_LABELS.body }}</label>
          <textarea
            :value="fields.body ?? ''"
            rows="18"
            class="mt-1 min-h-[24rem] w-full resize-y rounded-md border border-slate-300 px-3 py-3 text-[15px] leading-7 focus:border-slate-400 focus:outline-none"
            placeholder="正文内容…（长文可直接粘贴）"
            @input="emit('updateField', 'body', ($event.target as HTMLTextAreaElement).value)"
          />
        </div>

        <div>
          <span class="block text-xs font-medium text-slate-500">文中配图建议</span>
          <p class="mt-0.5 text-xs text-slate-400">按需填写；不需要配图则保持为空。</p>
          <div class="mt-1.5">
            <ArticleImageSuggestions
              :suggestions="fields.image_suggestions"
              @add="emit('addImage')"
              @remove="emit('removeImage', $event)"
              @update-item="(index, patch) => emit('updateImage', index, patch)"
            />
          </div>
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-500">封面建议</label>
          <textarea
            :value="fields.cover_suggestion ?? ''"
            rows="2"
            class="mt-1 w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            placeholder="封面图建议（可留空）"
            @input="emit('updateField', 'cover_suggestion', ($event.target as HTMLTextAreaElement).value)"
          />
        </div>
      </section>

      <!-- 制作子项视觉预留（轻量，不做 Workflow Builder） -->
      <section class="rounded-lg border border-slate-200 bg-white px-5 py-3">
        <p class="text-xs font-medium text-slate-500">后续制作</p>
        <div class="mt-1.5 flex flex-wrap gap-2 text-xs text-slate-500">
          <span class="rounded-full bg-slate-100 px-2.5 py-1">文章内容</span>
          <span class="rounded-full bg-slate-100 px-2.5 py-1">配图</span>
          <span class="rounded-full bg-slate-100 px-2.5 py-1">排版</span>
        </div>
      </section>

      <!-- 保存 -->
      <footer
        class="sticky bottom-0 flex items-center justify-end gap-3 rounded-lg border border-slate-200 bg-white/95 px-4 py-3 backdrop-blur"
      >
        <button
          type="button"
          :disabled="saving"
          class="rounded-md bg-slate-900 px-5 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
          @click="emit('save')"
        >
          {{ saving ? '保存中…' : '保存文章' }}
        </button>
      </footer>
    </template>
  </div>
</template>
