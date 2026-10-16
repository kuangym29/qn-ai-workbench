<script setup lang="ts">
// 只读时间轴 —— 日常页面不展示复杂时间轴；点击「查看时间轴」后以只读方式展开。
// 展示：片段 / 起止时间 / 旁白对应关系。不做专业剪辑软件式 timeline。
import { computed } from 'vue';
import type { VideoTimelineItem } from '../../../types/v2/video/video';
import { formatDuration } from '../../../composables/v2/video/videoCore';

const props = defineProps<{ timeline: VideoTimelineItem[] }>();

const total = computed(() =>
  props.timeline.length > 0
    ? props.timeline[props.timeline.length - 1].end_seconds
    : 0,
);

function range(item: VideoTimelineItem): string {
  return `${formatDuration(item.start_seconds)} – ${formatDuration(item.end_seconds)}`;
}
</script>

<template>
  <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50" data-v2-video-timeline>
    <div class="border-b border-slate-100 px-4 py-2 text-xs font-medium text-slate-500">
      时间轴（只读）· 总长 {{ formatDuration(total) }}
    </div>
    <div class="divide-y divide-slate-100">
      <div
        v-for="(item, i) in timeline"
        :key="`tl-${item.order}-${i}`"
        class="flex flex-wrap items-baseline gap-3 px-4 py-2 text-sm"
      >
        <span class="w-8 shrink-0 font-medium text-slate-600">
          {{ String(i + 1).padStart(2, '0') }}
        </span>
        <span class="w-24 shrink-0 text-slate-700">
          {{ item.source_label ?? `来源第 ${item.order} 片段` }}
        </span>
        <span class="w-28 shrink-0 font-mono text-xs text-slate-500">{{ range(item) }}</span>
      </div>
    </div>
  </div>
</template>
