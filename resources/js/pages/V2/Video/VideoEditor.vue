<script setup lang="ts">
import ReviewSubmission from '../../../components/V2/Review/ReviewSubmission.vue';
import PublicationAction from '../../../components/V2/Publication/PublicationAction.vue';
// V2 视频号视频制作 —— 正式 Inertia 页面。
// R1：正式页面必须接收 projectId / itemId / branchId 三参数（branch-aware）。
// 数据从 API 加载（videoApi）；生成按钮（复制任务 / 试样 / 选择片段）为 UI 动作，
// 保持 disabled pending integration，真实视频生成由后续后端提供。
//
// 注意：本任务未修改 routes/web.php；此页面的正式路由由 Work 主程序集成时接线。
import { shallowRef, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../layouts/AdminLayout.vue';
import PageHeader from '../../../components/PageHeader.vue';
import Breadcrumb from '../../../components/Breadcrumb.vue';
import { useVideoEditor } from '../../../composables/v2/video/useVideoEditor';
import VideoEditorBody from '../../../components/V2/Video/VideoEditorBody.vue';
import { is404 } from '../../../api/errors';
import { toast } from '../../../ui/toast';

const props = defineProps<{
  projectId: string | number;
  itemId: string | number;
  branchId: string | number;
}>();

const editor = shallowRef(useVideoEditor(props.projectId, props.itemId, props.branchId));

function handleScopeError(e: unknown): void {
  if (is404(e)) {
    toast.error('当前项目或篇目不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  editor.value.loadError.value = '加载失败';
}

watch(() => [props.projectId, props.itemId, props.branchId], async () => {
  const instance = useVideoEditor(props.projectId, props.itemId, props.branchId);
  editor.value = instance;
  try {
    await instance.load();
  } catch (e) {
    if (editor.value !== instance) return;
    handleScopeError(e);
  }
}, { immediate: true });
</script>

<template>
  <div class="v2-video-workspace">
  <AdminLayout>
    <Breadcrumb
      :items="[
        { label: '项目', href: '/projects' },
        { label: '内容库', href: `/projects/${projectId}/content` },
        { label: editor.video.value?.title ?? '视频制作' },
        { label: '视频号视频制作' },
      ]"
    />

    <PageHeader title="视频号 · 视频制作" description="基于已经确定的图片和内容，记录怎么动、动多久、怎么说。">
      <template #actions>
        <Link
          :href="`/projects/${projectId}/content/${itemId}`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回篇目
        </Link>
      </template>
    </PageHeader>

    <ReviewSubmission :project-id="projectId" :item-id="itemId" :branch-id="branchId" target="video_production" :dirty="editor.dirty.value" :busy='editor.loading.value || editor.saving.value || editor.resolvingSource.value' :ready="!!editor.video.value && !editor.loadError.value" />
    <PublicationAction :project-id="projectId" :item-id="itemId" :branch-id="branchId" target="video_production" :dirty="editor.dirty.value" :busy='editor.loading.value || editor.saving.value || editor.resolvingSource.value' :ready="!!editor.video.value && !editor.loadError.value" />
    <VideoEditorBody
      :video="editor.video.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :resolving-source="editor.resolvingSource.value"
      :timeline-open="editor.timelineOpen.value"
      :total-duration="editor.totalDuration.value"
      :duration-preview="editor.durationPreview.value"
      :narration-note="editor.narrationNote.value"
      :dirty="editor.dirty.value"
      :timeline="editor.timeline.value"
      @back="router.visit(`/projects/${projectId}/content`)"
      @save="editor.save()"
      @toggle-timeline="editor.timelineOpen.value = !editor.timelineOpen.value"
      @update:batch-instruction="editor.updateBatchInstruction($event)"
      @update:narration="editor.updateNarration($event)"
      @update:dynamic="(id, value) => editor.updateUnit(id, { dynamic_instruction: value })"
      @update:duration="(id, value) => editor.updateUnit(id, { duration_seconds: value })"
      @update:note="(id, value) => editor.updateUnit(id, { note: value })"
      @copy-batch="toast.info('复制整批视频任务：待后端提供')"
      @sample-first="toast.info('先做试样：待后端提供')"
      @select-segments="toast.info('选择片段生成：待后端提供')"
      @view-source-changes="toast.info(editor.video.value?.source_change_summary?.message ?? '来源内容已更新，请返回篇目查看当前内容。')"
      @keep-source-changes="editor.keepSourceChanges()"
    />
  </AdminLayout>
  </div>
</template>

<style scoped>
@media (max-width: 639px) {
  .v2-video-workspace :deep(header) { flex-wrap: wrap; gap: 0.5rem; padding-left: 0.75rem; padding-right: 0.75rem; }
  .v2-video-workspace :deep(header > div) { flex-wrap: wrap; min-width: 0; max-width: 100%; }
  .v2-video-workspace :deep(main) { min-width: 0; padding: 0.75rem; }
}
</style>
