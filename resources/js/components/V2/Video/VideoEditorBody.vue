<script setup lang="ts">
// V2 视频号视频制作编辑器主体 —— 纯展示 + 局部交互（不依赖 Inertia / API）。
// 正式页面（pages/V2/Video/VideoEditor.vue）与独立开发预览共用本组件。
//
// 页面只有三个核心块：
//   1. 整批视频制作说明（一段完整文本）
//   2. 完整旁白稿（一份完整正式旁白）
//   3. 视频单元（动态 / 时长 / 备注）
// 底部：预计总时长 + [复制整批视频任务] [先做试样] [选择片段生成]（仅 UI / emit）。
// 来源修改提醒：⚠ 来源内容后来有修改 → [查看修改] [继续沿用]（不显示技术版本术语）。
import type { VideoProduction, VideoTimelineItem } from '../../../types/v2/video/video';
import { formatDuration } from '../../../composables/v2/video/videoCore';
import LoadingState from '../../LoadingState.vue';
import ErrorState from '../../ErrorState.vue';
import VideoUnits from './VideoUnits.vue';
import VideoTimeline from './VideoTimeline.vue';

const props = defineProps<{
  video: VideoProduction | null;
  loading: boolean;
  loadError: string;
  saving: boolean;
  timelineOpen: boolean;
  totalDuration: number;
  timeline: VideoTimelineItem[];
}>();
const emit = defineEmits<{
  back: [];
  save: [];
  toggleTimeline: [];
  'update:batchInstruction': [value: string];
  'update:narration': [value: string];
  'update:dynamic': [unitId: number, value: string];
  'update:duration': [unitId: number, value: number];
  'update:note': [unitId: number, value: string];
  copyBatch: [];
  sampleFirst: [];
  selectSegments: [];
  viewSourceChanges: [];
  keepSourceChanges: [];
}>();
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <!-- 顶部：只显示内容名称 + 来源 ›（不增加视频名称/视频核心表达等新字段区） -->
    <header class="rounded-lg border border-slate-200 bg-white p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-semibold text-slate-900">{{ video?.title ?? '视频号 · 视频制作' }}</h1>
          <p v-if="video?.source_label" class="mt-1 text-sm text-slate-500">来源 › {{ video.source_label }}</p>
        </div>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
          @click="emit('back')"
        >
          返回
        </button>
      </div>

      <!-- 来源内容变化提醒（轻量） -->
      <div
        v-if="video?.source_changed"
        class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3"
        data-v2-video-source-changed
      >
        <span class="text-sm text-amber-800">
          ⚠ {{ video.source_change_summary ?? '来源内容后来有修改，建议检查本视频是否受影响。' }}
        </span>
        <span class="flex gap-2">
          <button
            type="button"
            class="rounded-md border border-amber-300 px-3 py-1.5 text-xs text-amber-800 hover:bg-amber-100"
            @click="emit('viewSourceChanges')"
          >
            查看修改
          </button>
          <button
            type="button"
            class="rounded-md border border-amber-300 px-3 py-1.5 text-xs text-amber-800 hover:bg-amber-100"
            @click="emit('keepSourceChanges')"
          >
            继续沿用
          </button>
        </span>
      </div>
    </header>

    <!-- 加载 / 错误 -->
    <LoadingState v-if="loading" label="加载视频制作…" />
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

    <template v-else-if="video">
      <!-- 一、整批视频制作说明 -->
      <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">整批视频制作说明</h2>
        <p class="mt-0.5 text-xs text-slate-400">描述整组怎么动、节奏、整体动态、转场倾向、人物动作原则等。</p>
        <textarea
          :value="video.batch_instruction"
          rows="4"
          class="mt-2 w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm leading-6 focus:border-slate-400 focus:outline-none"
          placeholder="整批视频怎么做…"
          @input="emit('update:batchInstruction', ($event.target as HTMLTextAreaElement).value)"
        />
      </section>

      <!-- 二、完整旁白稿 -->
      <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">完整旁白稿</h2>
        <p class="mt-0.5 text-xs text-slate-400">一份完整正式旁白；系统未来自动分配到时间轴，不要求手工逐片段拆分。</p>
        <textarea
          :value="video.narration"
          rows="10"
          class="mt-2 min-h-[16rem] w-full resize-y rounded-md border border-slate-300 px-3 py-3 text-[15px] leading-7 focus:border-slate-400 focus:outline-none"
          placeholder="完整旁白…"
          @input="emit('update:narration', ($event.target as HTMLTextAreaElement).value)"
        />
      </section>

      <!-- 三、视频单元 -->
      <section class="space-y-3 rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold text-slate-900">视频片段</h2>
            <p class="mt-0.5 text-xs text-slate-400">每个来源图片 / 页面对应一个单元；主要编辑动态、时长与备注。</p>
          </div>
          <button
            type="button"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
            @click="emit('toggleTimeline')"
          >
            {{ timelineOpen ? '收起时间轴' : '查看时间轴' }}
          </button>
        </div>

        <VideoTimeline v-if="timelineOpen" :timeline="timeline" />

        <VideoUnits
          :units="video.units"
          @update:dynamic="(id, value) => emit('update:dynamic', id, value)"
          @update:duration="(id, value) => emit('update:duration', id, value)"
          @update:note="(id, value) => emit('update:note', id, value)"
        />
      </section>

      <!-- 底部：预计总时长 + 动作按钮（仅 UI / emit，不开发真实视频生成） -->
      <footer class="rounded-lg border border-slate-200 bg-white px-5 py-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-slate-500">
            预计总时长：
            <span class="font-semibold text-slate-800">{{ formatDuration(totalDuration) }}</span>
          </p>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
              @click="emit('copyBatch')"
            >
              复制整批视频任务
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
              @click="emit('sampleFirst')"
            >
              先做试样
            </button>
            <button
              type="button"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
              @click="emit('selectSegments')"
            >
              选择片段生成
            </button>
            <button
              type="button"
              :disabled="saving"
              class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              @click="emit('save')"
            >
              {{ saving ? '保存中…' : '保存' }}
            </button>
          </div>
        </div>
      </footer>
    </template>
  </div>
</template>
