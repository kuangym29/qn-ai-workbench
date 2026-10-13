<script setup lang="ts">
// V2-P3-C03 独立开发预览 App（DEV ONLY）—— 挂载视频制作编辑器主体。
// 不依赖 Laravel / Inertia / 后端 API；保存为内存动作。
// 底部按钮（复制任务 / 试样 / 选择片段）只 emit toast，不开发真实视频生成。
import { useVideoEditor } from '../../../composables/v2/video/useVideoEditor';
import type { VideoProduction } from '../../../types/v2/video/video';
import VideoEditorBody from './VideoEditorBody.vue';
import ToastHost from '../../ToastHost.vue';
import { toast } from '../../../ui/toast';

const DEV_VIDEO: VideoProduction = {
  id: 1,
  project_id: 1,
  content_item_id: 1,
  title: '孩子说“我自己来”时，先别急着帮',
  source_label: '生活小能力 · 孩子出门总磨蹭',
  batch_instruction:
    '整体节奏舒缓，运镜以固定机位 + 轻微推近为主；人物动作遵循「等一等、不代劳」原则，转场用淡入淡出，避免快速切换。',
  narration:
    '出门前，孩子总有自己的节奏。\n\n他把鞋带绕了一圈又一圈，小手笨拙却很认真。\n\n妈妈站在门口，看着时间一分一秒过去，忍住没有蹲下去。\n\n“你自己来。”她说。\n\n几分钟后，孩子抬起头，眼睛亮亮的：“我系好了！”\n\n那一句“我自己来”，要的不是立刻被满足，而是被允许练习。\n\n你在身边，“我自己来”更有底气。',
  units: [
    { id: 1, order: 1, source_page_id: 101, source_label: '封面', dynamic_instruction: '封面缓慢推近，孩子系鞋带的背影，光线柔和。', duration_seconds: 3, note: null },
    { id: 2, order: 2, source_page_id: 102, source_label: 'P2', dynamic_instruction: '鞋带绕圈的微距特写，轻微旋转。', duration_seconds: 4, note: '注意手部动作连贯' },
    { id: 3, order: 3, source_page_id: 103, source_label: 'P3', dynamic_instruction: '妈妈站在门口看表，镜头缓慢横移。', duration_seconds: 4, note: null },
    { id: 4, order: 4, source_page_id: 104, source_label: 'P4', dynamic_instruction: '孩子抬头说话瞬间定格半秒，再继续。', duration_seconds: 3, note: '眼神特写' },
    { id: 5, order: 5, source_page_id: 105, source_label: 'P5', dynamic_instruction: '孩子眼睛亮起来，镜头快推至面部。', duration_seconds: 3, note: null },
    { id: 6, order: 6, source_page_id: 106, source_label: 'P6', dynamic_instruction: '结尾定格：孩子在前面走，妈妈在后面微笑跟。', duration_seconds: 5, note: '收尾点题' },
  ],
  source_changed: true,
  source_change_summary: '来源内容后来有修改，建议检查本视频是否受影响。',
};

const editor = useVideoEditor(1, 1);
editor.video.value = { ...DEV_VIDEO };
editor.loading.value = false;

// DEV：保存走内存 toast；按钮为演示动作。
editor.save = async () => {
  editor.saving.value = true;
  await new Promise((resolve) => setTimeout(resolve, 200));
  editor.saving.value = false;
  toast.success('已保存视频制作（DEV 内存）');
  return true;
};

function demoAction(label: string): void {
  toast.info(`[DEV] ${label}：真实视频生成由后续后端提供。`);
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
      DEV PREVIEW：固定示例数据；保存与动作按钮为 DEV 内存/提示，不请求后端；
      正式数据由 WorkBuddy 后端接线后提供。
    </div>

    <VideoEditorBody
      :video="editor.video.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
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
      @keep-source-changes="toast.success('已继续沿用当前视频制作')"
    />

    <ToastHost />
  </div>
</template>
