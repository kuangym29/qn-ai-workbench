<script setup lang="ts">
// 每页内容卡 —— 固定 8 项信息，但 UI 保持克制：
//   - 页码 + 页面类型固定在卡片头部；
//   - 常用信息（最终文字 / 画面证据 / 新事实）优先展示；
//   - 制作备注弱化（小字、默认折叠）；
//   - fixed_back_cover 明确为固定内容，字段只读保护。
import { computed, ref, watch } from 'vue';
import type { ImagePostPage } from '../../types/v2/imagePost';
import { IMAGE_POST_FIELD_LABELS, IMAGE_POST_FIELD_ORDER } from '../../types/v2/imagePost';
import { PAGE_TYPE_LABELS } from '../../api/types';
import Badge from '../Badge.vue';

const props = defineProps<{
  page: ImagePostPage;
  /** 是否展示为「当前正在编辑」的强调态 */
  active?: boolean;
}>();
const emit = defineEmits<{
  select: [pageNo: number];
  update: [page: ImagePostPage];
}>();

const editing = ref(false);
const draft = ref<ImagePostPage>({ ...props.page });

watch(() => props.page, page => { draft.value = { ...page }; editing.value = false; });

const isFixedBackCover = computed(() => props.page.page_type === 'fixed_back_cover');

/** 展示模式：按常用优先顺序过滤出有内容的字段（制作备注单独弱化）。 */
const visibleFields = computed(() =>
  IMAGE_POST_FIELD_ORDER.filter((f) => {
    if (f === 'production_note') return false; // 备注在弱化区单独展示
    return props.page[f] !== null && String(props.page[f]).trim() !== '';
  }),
);

const showNote = ref(false);

function enterEdit(): void {
  if (isFixedBackCover.value) return;
  draft.value = { ...props.page };
  editing.value = true;
}

function cancelEdit(): void {
  editing.value = false;
}

function saveEdit(): void {
  emit('update', { ...draft.value });
  editing.value = false;
}

</script>

<template>
  <article
    class="rounded-lg border bg-white transition-shadow"
    :class="[active ? 'border-slate-400 shadow-sm' : 'border-slate-200']"
    data-v2-image-post-page
  >
    <!-- 头部：页码 + 页面类型 + 操作 -->
    <header class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
      <span
        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white"
      >
        {{ page.page_no }}
      </span>
      <Badge :variant="isFixedBackCover ? 'muted' : 'planning'" :label="PAGE_TYPE_LABELS[page.page_type]" />
      <span v-if="isFixedBackCover" class="text-xs text-slate-400">固定内容 · 只读</span>
      <div class="ml-auto flex items-center gap-2">
        <template v-if="!editing">
          <button
            v-if="!isFixedBackCover"
            type="button"
            class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"
            @click="enterEdit"
          >
            编辑
          </button>

        </template>
        <template v-else>
          <button
            type="button"
            class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"
            @click="cancelEdit"
          >
            取消
          </button>
          <button
            type="button"
            class="rounded bg-slate-900 px-2 py-1 text-xs font-medium text-white hover:bg-slate-800"
            @click="saveEdit"
          >
            完成编辑
          </button>
        </template>
      </div>
    </header>

    <!-- 展示模式 -->
    <div v-if="!editing" class="space-y-2 px-4 py-3">
      <template v-if="isFixedBackCover">
        <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
          固定封底使用品牌既有模板，本篇不新增文案，不可编辑。
        </p>
      </template>

      <dl v-for="field in visibleFields" :key="field" class="flex gap-3">
        <dt class="w-24 shrink-0 pt-0.5 text-xs text-slate-400">{{ IMAGE_POST_FIELD_LABELS[field] }}</dt>
        <dd class="min-w-0 flex-1 whitespace-pre-wrap break-words text-sm text-slate-700">
          {{ page[field] }}
        </dd>
      </dl>

      <p v-if="!visibleFields.length && !isFixedBackCover" class="text-sm text-slate-400">
        本页暂无内容，点击「编辑」填写。
      </p>

      <!-- 弱化区：制作备注 -->
      <div v-if="page.production_note" class="border-t border-slate-100 pt-2">
        <button
          type="button"
          class="text-xs text-slate-400 underline"
          @click="showNote = !showNote"
        >
          {{ showNote ? '收起制作备注' : '制作备注' }}
        </button>
        <p v-if="showNote" class="mt-1 whitespace-pre-wrap break-words text-xs text-slate-500">
          {{ page.production_note }}
        </p>
      </div>
    </div>

    <!-- 编辑模式（fixed_back_cover 永不进入） -->
    <div v-else class="space-y-3 px-4 py-3">
      <label v-for="field in IMAGE_POST_FIELD_ORDER" :key="field" class="block">
        <span class="text-xs font-medium text-slate-600">{{ IMAGE_POST_FIELD_LABELS[field] }}</span>
        <textarea
          v-model="draft[field]"
          :rows="field === 'final_copy' || field === 'visual_evidence' ? 3 : 2"
          class="mt-1 w-full resize-y rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-slate-400 focus:outline-none"
          :placeholder="field === 'production_note' ? '仅制作环节参考，弱化展示' : ''"
        />
      </label>
    </div>
  </article>
</template>
