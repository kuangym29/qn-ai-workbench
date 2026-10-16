<script setup lang="ts">
import ReviewSubmission from '../../../components/V2/Review/ReviewSubmission.vue';
// V2 公众号图文文章编辑器 —— 正式 Inertia 页面。
// 数据从 API 加载（articleApi，R1 branch-aware）；AI 导入为后端 preview/apply 链路。
// 页面只依赖 props（projectId / itemId / branchId），不根据 URL 自动选项目。
//
// 入口由 ArticleImportController 校验项目、篇目、分支与渠道。
import { shallowRef, watch, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../layouts/AdminLayout.vue';
import PageHeader from '../../../components/PageHeader.vue';
import Breadcrumb from '../../../components/Breadcrumb.vue';
import { useArticleEditor, newImageDraft } from '../../../composables/v2/article/useArticleEditor';
import ArticleEditorBody from '../../../components/V2/Article/ArticleEditorBody.vue';

const props = defineProps<{
  projectId: string | number;
  itemId: string | number;
  branchId: string | number;
  columnName: string;
  source: { topic: { id: number; title: string } | null; inspiration: { id: number; body: string } | null };
}>();

const editor = shallowRef(useArticleEditor(props.projectId, props.itemId, props.branchId));
watch(() => [props.projectId, props.itemId, props.branchId], () => {
  editor.value = useArticleEditor(props.projectId, props.itemId, props.branchId);
  void editor.value.load();
}, { immediate: true });
const displayItem = computed(() => editor.value.item.value ? {
  ...editor.value.item.value, column_name: props.columnName,
  column_slug: props.source.topic?.title ?? props.source.inspiration?.body ?? '',
} : null);
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

    <ReviewSubmission :project-id="projectId" :item-id="itemId" :branch-id="branchId" target="article_copy" :dirty="editor.dirty.value" :busy='editor.busy.value || editor.phase.value === "preview"' :ready="!!editor.item.value && !editor.loadError.value" />
    <ArticleEditorBody
      :item="displayItem"
      :fields="editor.fields.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :busy="editor.busy.value"
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
      @back="router.visit(`/projects/${projectId}/content/${itemId}`)"
    />
  </AdminLayout>
</template>
