<script setup lang="ts">
// 视频单元单行 —— 用户主要编辑：动态说明 / 时长 / 备注。
// 序号与来源页为系统/继承信息，弱展示。
import type { VideoUnit } from '../../../types/v2/video/video';

defineProps<{ unit: VideoUnit; index: number }>();
const emit = defineEmits<{
  'update:dynamic': [unitId: number, value: string];
  'update:duration': [unitId: number, value: number];
  'update:note': [unitId: number, value: string];
}>();

function toSeconds(v: string): number {
  const n = Number(v);
  return Number.isFinite(n) && n >= 0 ? n : NaN;
}
</script>

<template>
  <div class="rounded-lg border border-slate-200 bg-white p-4" data-v2-video-unit>
    <div class="mb-2 flex items-baseline justify-between gap-3">
      <span class="text-sm font-semibold text-slate-700">
        {{ String(index + 1).padStart(2, '0') }}
        <span v-if="unit.source_label" class="ml-2 text-xs font-normal text-slate-400">
          · 来源{{ unit.source_label }}
        </span>
      </span>
      <span class="text-xs text-slate-400">片段</span>
    </div>

    <div class="grid gap-3 sm:grid-cols-[1fr_6rem_1fr]">
      <div class="min-w-0">
        <label class="block text-xs font-medium text-slate-500">动态</label>
        <textarea
          :value="unit.dynamic_instruction"
          rows="2"
          class="mt-1 w-full resize-y rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="这张图怎么动…"
          @input="emit('update:dynamic', unit.source_page_id, ($event.target as HTMLTextAreaElement).value)"
        />
      </div>
      <div class="min-w-0">
        <label class="block text-xs font-medium text-slate-500">时长</label>
        <div class="mt-1 flex items-center gap-1">
          <input
            :value="unit.duration_seconds"
            type="text"
            inputmode="decimal"
            class="w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-slate-400 focus:outline-none"
            @change="
              (e) => {
                const v = toSeconds((e.target as HTMLInputElement).value);
                if (!Number.isNaN(v)) emit('update:duration', unit.source_page_id, v);
              }
            "
          />
          <span class="text-xs text-slate-400">s</span>
        </div>
      </div>
      <div class="min-w-0">
        <label class="block text-xs font-medium text-slate-500">备注（可选）</label>
        <input
          :value="unit.note ?? ''"
          type="text"
          class="mt-1 w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="特殊注意…"
          @input="emit('update:note', unit.source_page_id, ($event.target as HTMLInputElement).value)"
        />
      </div>
    </div>
  </div>
</template>
