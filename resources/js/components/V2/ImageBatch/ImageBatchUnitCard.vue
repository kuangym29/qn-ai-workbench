<script setup lang="ts">
// V2 图片批次 —— 页面单元卡（系统自动拆页，用户查看 / 轻量修正 / 选择参与本次生成）。
// 原则：每页不需要重新完整录入；卡片以「最终文字弱展示 + 画面证据 + 人物 + 场景」为主，
// 制作备注 / 特殊要求弱化；单页状态轻量；来源内容变化给轻量提醒。
import { ref } from 'vue';
import type { ImageBatchUnit } from '../../../types/v2/imageBatch/imageBatch';
import { pageLabel, pageTypeLabel, unitStatusLabel } from '../../../composables/v2/imageBatch/imageBatchCore';

const props = defineProps<{ unit: ImageBatchUnit; disabled?: boolean }>();

const emit = defineEmits<{
  toggle: [unit: ImageBatchUnit];
  update: [unit: ImageBatchUnit];
  viewChange: [unit: ImageBatchUnit];
  keepCurrent: [unit: ImageBatchUnit];
  regenerate: [unit: ImageBatchUnit];
}>();

const statusTone: Record<string, string> = {
  pending: 'bg-slate-100 text-slate-600',
  generated: 'bg-emerald-100 text-emerald-700',
  needs_revision: 'bg-amber-100 text-amber-700',
  approved: 'bg-blue-100 text-blue-700',
};

// 轻量修正：只允许编辑「特殊要求」一个弱化字段，保存后 emit update。
const editing = ref(false);
const draft = ref('');
function startEdit(): void {
  draft.value = props.unit.special_requirement ?? '';
  editing.value = true;
}
function saveEdit(): void {
  emit('update', { ...props.unit, special_requirement: draft.value.trim() || null });
  editing.value = false;
}
function cancelEdit(): void {
  editing.value = false;
}
</script>

<template>
  <article
    class="rounded-lg border border-slate-200 bg-white p-4"
    :data-image-batch-unit="`P${unit.page_no}`"
  >
    <div class="flex items-start justify-between gap-2">
      <div class="flex min-w-0 items-center gap-2">
        <label class="flex items-center gap-1.5 text-sm">
          <input
            type="checkbox"
            class="h-4 w-4 rounded border-slate-300"
            :checked="unit.selected"
            :disabled="disabled"
            @change="emit('toggle', unit)"
          />
          <span class="font-semibold text-slate-900">{{ pageLabel(unit.page_no) }}</span>
        </label>
        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">
          {{ pageTypeLabel(unit.page_type) }}
        </span>
        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusTone[unit.status] ?? statusTone.pending">
          {{ unitStatusLabel(unit.status) }}
        </span>
      </div>
      <button
        v-if="!editing"
        type="button"
        class="text-xs text-slate-400 hover:text-slate-600"
        :disabled="disabled"
        @click="startEdit"
      >
        轻量修正
      </button>
    </div>

    <!-- 轻量修正：特殊要求 inline 编辑 -->
    <div v-if="editing" class="mt-2 rounded-md border border-slate-200 bg-slate-50 p-2">
      <label class="block text-xs text-slate-500" :for="'batch-special-' + unit.id">特殊要求（仅此页）</label>
      <textarea
        :id="'batch-special-' + unit.id"
        v-model="draft"
        rows="2"
        class="mt-1 w-full resize-y rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-800 focus:border-slate-400 focus:outline-none"
        placeholder="本页生图特殊要求（可选）"
      />
      <div class="mt-2 flex gap-2">
        <button
          type="button"
          class="rounded-md bg-slate-900 px-3 py-1 text-xs font-medium text-white hover:bg-slate-800"
          @click="saveEdit"
        >
          保存
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-100"
          @click="cancelEdit"
        >
          取消
        </button>
      </div>
    </div>

    <!-- 来源内容变化提醒（轻量，不显示技术 Revision 编号） -->
    <div
      v-if="unit.source_changed"
      class="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
      role="status"
    >
      <span>⚠ 来源内容后来有修改，建议检查本页图片是否受影响。</span>
      <button type="button" class="underline hover:text-amber-900" @click="emit('viewChange', unit)">查看修改</button>
      <button type="button" class="underline hover:text-amber-900" @click="emit('keepCurrent', unit)">继续沿用</button>
      <button type="button" class="underline hover:text-amber-900" @click="emit('regenerate', unit)">重新生成</button>
    </div>
    <p v-if="unit.source_changed && unit.source_change_summary" class="mt-1 text-xs text-amber-700">
      {{ unit.source_change_summary }}
    </p>

    <!-- 最终文字（弱展示） -->
    <p class="mt-3 line-clamp-2 text-sm text-slate-700">{{ unit.final_copy ?? '（无最终文字）' }}</p>

    <!-- 画面证据 -->
    <p class="mt-2 text-xs text-slate-500">
      <span class="text-slate-400">画面证据：</span>{{ unit.visual_evidence ?? '—' }}
    </p>

    <!-- 人物 / 场景 / 特殊要求 -->
    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
      <span>
        <span class="text-slate-400">人物：</span>
        <template v-if="unit.characters.length">{{ unit.characters.join('、') }}</template>
        <template v-else>—</template>
      </span>
      <span><span class="text-slate-400">场景：</span>{{ unit.scene ?? '—' }}</span>
      <span v-if="unit.special_requirement" class="text-slate-600">
        <span class="text-slate-400">特殊要求：</span>{{ unit.special_requirement }}
      </span>
    </div>
  </article>
</template>
