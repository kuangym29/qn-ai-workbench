<script setup lang="ts">
// V2-P3-C03 独立开发预览 App（DEV ONLY）—— 挂载视频制作编辑器主体。
// 不依赖 Laravel / Inertia / 后端 API；通过注入 DEV mock client 模拟完整链路：
//   GET（内存）→ 编辑 → PUT（内存）→ 重新 GET；
//   来源变化「继续沿用」→ POST source-change（内存）→ 重新 GET。
// 底部生成按钮（复制任务 / 试样 / 选择片段）仅提示，不开发真实视频生成。
//
// 正式 composable（useVideoEditor）0 引用本 DEV Mock；Mock 仅在此处注入。
import { onMounted } from 'vue';
import { useVideoEditor } from '../../../composables/v2/video/useVideoEditor';
import { createMockVideoClient } from '../../../composables/v2/video/videoDevMock';
import VideoEditorBody from './VideoEditorBody.vue';
import ToastHost from '../../ToastHost.vue';
import { toast } from '../../../ui/toast';

// DEV 注入：projectId / itemId / branchId 三参数 + 内存 mock client。
const mock = createMockVideoClient();
const editor = useVideoEditor(1, 1, 1, mock.client);

onMounted(async () => {
  await editor.load();
});

function demoAction(label: string): void {
  toast.info(`[DEV] ${label}：真实视频生成由后续后端提供（待集成，当前不会真正提交）。`);
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
      DEV PREVIEW：固定示例数据（内存 Mock client）；保存、来源「继续沿用」均为内存动作，
      不请求后端；正式数据由 WorkBuddy 后端按 branch-aware 合同接线后提供。
    </div>

    <VideoEditorBody
      :video="editor.video.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :resolving-source="editor.resolvingSource.value"
      :timeline-open="editor.timelineOpen.value"
      :total-duration="editor.totalDuration.value"
      :timeline="editor.timeline.value"
      @back="() => {}"
      @save="editor.save()"
      @toggle-timeline="editor.timelineOpen.value = !editor.timelineOpen.value"
      @update:batch-instruction="editor.updateBatchInstruction($event)"
      @update:narration="editor.updateNarration($event)"
      @update:dynamic="(id, value) => editor.updateUnit(id, { dynamic_instruction: value })"
      @update:duration="(id, value) => editor.updateUnit(id, { duration_seconds: value })"
      @update:note="(id, value) => editor.updateUnit(id, { note: value })"
      @copy-batch="demoAction('复制整批视频任务')"
      @sample-first="demoAction('先做试样')"
      @select-segments="demoAction('选择片段生成')"
      @view-source-changes="demoAction('查看修改')"
      @keep-source-changes="editor.keepSourceChanges()"
    />

    <ToastHost />
  </div>
</template>
