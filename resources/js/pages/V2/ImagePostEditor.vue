<script setup lang="ts">
import ReviewSubmission from '../../components/V2/Review/ReviewSubmission.vue';
import PublicationAction from '../../components/V2/Publication/PublicationAction.vue';
import { shallowRef, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import ImagePostEditorBody from '../../components/V2/ImagePostEditorBody.vue';
import { useImagePostEditor } from '../../composables/v2/useImagePostEditor';
import type { ImagePostSource } from '../../types/v2/imagePost';
const props = defineProps<{ projectId: string | number; itemId: string | number; branchId: string | number; columnName: string; source: ImagePostSource }>();
const editor = shallowRef(useImagePostEditor(props.projectId, props.itemId, props.branchId));
watch(() => [props.projectId, props.itemId, props.branchId], () => {
 editor.value = useImagePostEditor(props.projectId, props.itemId, props.branchId);
 void editor.value.load();
}, { immediate: true });
</script>
<template>
 <AdminLayout>
  <Breadcrumb :items="[{label:'内容库',href:'/projects/'+projectId+'/content'},{label:editor.item.value?.title ?? '图片文章',href:'/projects/'+projectId+'/content/'+itemId},{label:'图片文章编辑'}]" />
  <div class="mb-4"><Link :href="'/projects/'+projectId+'/content/'+itemId" class="text-sm text-slate-600 underline">返回篇目</Link></div>
  <ReviewSubmission :project-id="projectId" :item-id="itemId" :branch-id="branchId" target="image_post_copy" :dirty="editor.dirty.value" :busy='editor.loading.value || editor.saving.value || editor.applying.value || editor.phase.value === "parsing" || editor.phase.value === "preview"'     :ready="!!editor.item.value && !editor.loadError.value" />
    <PublicationAction :project-id="projectId" :item-id="itemId" :branch-id="branchId" target="image_post_copy" :dirty="editor.dirty.value" :busy='editor.loading.value || editor.saving.value || editor.applying.value || editor.phase.value === "parsing" || editor.phase.value === "preview"' :ready="!!editor.item.value && !editor.loadError.value" />
    <ImagePostEditorBody
   :item="editor.item.value" :pages="editor.pages.value" :loading="editor.loading.value" :load-error="editor.loadError.value"
   :saving="editor.saving.value" :applying="editor.applying.value" :apply-error="editor.applyError.value" :warnings="editor.warnings.value"
   :column-name="columnName" :source="source"
   :phase="editor.phase.value" :paste-text="editor.pasteText.value" :parse-error="editor.parseError.value"
   :preview="editor.preview.value" :applied-count="editor.appliedCount.value"
   @update:paste-text="editor.pasteText.value = $event" @parse="editor.runParse()" @confirm-import="editor.confirmImport()"
   @cancel-import="editor.closeImport()" @save="editor.save()" @update-page="editor.updatePage($event)"
   @update-core-message="editor.item.value ? (editor.item.value.core_message = $event) : null"
   @update-story-line="editor.item.value ? (editor.item.value.story_line = $event) : null"
   @back="router.visit('/projects/'+projectId+'/content/'+itemId)"
  />
 </AdminLayout>
</template>
