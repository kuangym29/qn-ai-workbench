<script setup lang="ts">
// V2 公众号图文文章编辑器 —— 正式 Inertia 页面。
// 数据从 API 加载（articleApi，R1 branch-aware）；AI 导入为后端 preview/apply 链路。
// 页面只依赖 props（projectId / itemId / branchId），不根据 URL 自动选项目。
//
// 注意：本任务未修改 routes/web.php；此页面的正式路由由 Work 主程序集成时接线。
import { onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../layouts/AdminLayout.vue';
import PageHeader from '../../../components/PageHeader.vue';
import Breadcrumb from '../../../components/Breadcrumb.vue';
import { useArticleEditor, newImageDraft } from '../../../composables/v2/article/useArticleEditor';
import ArticleEditorBody from '../../../components/V2/Article/ArticleEditorBody.vue';
import { is404 } from '../../../api/errors';
import { toast } from '../../../ui/toast';

const props = defineProps<{
  projectId: string | number;
  itemId: string | number;
  branchId: string | number;
}>();

const editor = useArticleEditor(props.projectId, props.itemId, props.branchId);

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
        { label: editor.item.value?.title ?? '图文文章' },
        { label: '图文文章编辑' },
      ]"
    />

    <PageHeader title="公众号图文文章" description="AI 导入为主要录入方式，人工编辑为修订入口。">
      <template #actions>
        <Link
          :href="`/projects/${projectId}/content/${itemId}`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回篇目
        </Link>
      </template>
    </PageHeader>

    <ArticleEditorBody
      :item="editor.item.value"
      :fields="editor.fields.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :phase="editor.phase.value"
      :paste-text="editor.pasteText.value"
      :parse-error="editor.parseError.value"
      :preview="editor.preview.value"
      @update:paste-text="editor.pasteText.value = $event"
      @parse="editor.runPreview()"
      @confirm-import="editor.confirmImport()"
      @cancel-import="editor.closeImport()"
      @save="editor.save()"
      @update-field="(key, value) => editor.updateField(key, value)"
      @add-image="editor.addImage(newImageDraft())"
      @remove-image="editor.removeImage($event)"
      @update-image="(index, patch) => editor.updateImage(index, patch)"
      @back="router.visit(`/projects/${projectId}/content`)"
    />
  </AdminLayout>
</template>
