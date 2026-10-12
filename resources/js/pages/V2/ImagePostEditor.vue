<script setup lang="ts">
// V2 公众号图片文章（小绿书）内容编辑器 —— 正式 Inertia 页面。
// 数据从 API 加载（imagePostApi）；AI 导入为主要录入方式。
// 页面只依赖 props（projectId / itemId），不根据 URL 自动选项目。
import { onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import { useImagePostEditor } from '../../composables/v2/useImagePostEditor';
import ImagePostEditorBody from '../../components/V2/ImagePostEditorBody.vue';
import { is404 } from '../../api/errors';
import { toast } from '../../ui/toast';

const props = defineProps<{
  projectId: string | number;
  itemId: string | number;
}>();

const editor = useImagePostEditor(props.projectId, props.itemId);

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
        {
          label: editor.item.value?.title ?? '图片文章',
          href: `/projects/${projectId}/content/${itemId}`,
        },
        { label: '图片文章编辑' },
      ]"
    />

    <PageHeader title="公众号图片文章" description="以 AI 导入为主，逐页核对最终文字与画面证据。">
      <template #actions>
        <Link
          :href="`/projects/${projectId}/content/${itemId}`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回篇目
        </Link>
      </template>
    </PageHeader>

    <ImagePostEditorBody
      :item="editor.item.value"
      :pages="editor.pages.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :phase="editor.phase.value"
      :paste-text="editor.pasteText.value"
      :parse-error="editor.parseError.value"
      :preview="editor.preview.value"
      :applied-count="editor.appliedCount.value"
      @update:paste-text="editor.pasteText.value = $event"
      @parse="editor.runParse()"
      @confirm-import="editor.confirmImport()"
      @cancel-import="editor.closeImport()"
      @save="editor.save()"
      @update-page="editor.updatePage($event)"
      @remove-page="editor.removePage($event)"
      @update-core-expression="
        editor.item.value ? (editor.item.value.core_expression = $event) : null
      "
      @update-story-line="editor.item.value ? (editor.item.value.story_line = $event) : null"
      @back="router.visit(`/projects/${projectId}/content`)"
    />
  </AdminLayout>
</template>
