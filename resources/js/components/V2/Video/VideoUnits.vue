<script setup lang="ts">
// 视频单元列表 —— 每个来源图片 / 页面对应一个单元。
import type { VideoUnit } from '../../../types/v2/video/video';
import VideoUnitRow from './VideoUnitRow.vue';

defineProps<{ units: VideoUnit[] }>();
const emit = defineEmits<{
  'update:dynamic': [unitId: number, value: string];
  'update:duration': [unitId: number, value: number];
  'update:note': [unitId: number, value: string];
}>();
</script>

<template>
  <div class="space-y-3" data-v2-video-units>
    <VideoUnitRow
      v-for="(u, i) in units"
      :key="u.source_page_id"
      :unit="u"
      :index="i"
      @update:dynamic="(id, value) => emit('update:dynamic', id, value)"
      @update:duration="(id, value) => emit('update:duration', id, value)"
      @update:note="(id, value) => emit('update:note', id, value)"
    />
    <p v-if="units.length === 0" class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-400">
      暂无视频单元。
    </p>
  </div>
</template>
