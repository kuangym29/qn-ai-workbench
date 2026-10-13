<script setup lang="ts">
// V2 视频号视频制作 —— 正式 Inertia 页面。
// 数据从 API 加载（videoApi）；按钮（复制任务 / 试样 / 选择片段）为 UI 动作，
// 真实视频生成由后续后端提供。
//
// 注意：本任务未修改 routes/web.php；此页面的正式路由由 Work 主程序集成时接线。
import { onMounted } from 'vue';
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
}>();

const editor = useVideoEditor(props.projectId, props.itemId);

function handleScopeError(e: unknown): void {
  if (is404(e)) {
    toast.error('当前项目或篇目不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  editor.loadError.value = '加载失败';
}

onMounted(async () => {
  try {
    await editor.load();
  } catch (e) {
    handleScopeError(e);
  }
});
</script>

<template>
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

    <VideoEditorBody
      :video="editor.video.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :timeline-open="editor.timelineOpen.value"
      :total-duration="editor.totalDuration.value"
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
      @view-source-changes="toast.info('查看修改：来源内容修改对比待后端提供')"
      @keep-source-changes="toast.success('已继续沿用当前视频制作')"
    />
  </AdminLayout>
</template>
