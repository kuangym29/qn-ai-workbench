<script setup lang="ts">
import ReviewSubmission from '../../../components/V2/Review/ReviewSubmission.vue';
// V2 图片批次生产 —— 正式 Inertia 页面。
// 图片生产以「整批」为主：整批说明 / 整批备注 / 资产摘要 / 页面单元 / 执行动作。
import { shallowRef, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../layouts/AdminLayout.vue';
import Breadcrumb from '../../../components/Breadcrumb.vue';
import ImageBatchWorkspaceBody from '../../../components/V2/ImageBatch/ImageBatchWorkspaceBody.vue';
import { useImageBatch } from '../../../composables/v2/imageBatch/useImageBatch';
import { createAssetOptionClient } from '../../../composables/v2/imageBatch/assetOptions';
import { useAssetOptions } from '../../../composables/v2/imageBatch/useAssetOptions';
import { http } from '../../../api/http';
import type { AssetIndexTransport } from '../../../composables/v2/reusableAssets/assetIndexApi';
import type { ImageBatchUnit } from '../../../types/v2/imageBatch/imageBatch';

const props = defineProps<{ projectId: string | number; itemId: string | number; branchId: string | number; columnName: string }>();

const editor = shallowRef(useImageBatch(props.projectId, props.itemId, props.branchId));
// P5-I03：可选人物 / 场景直接复用 P5-I02 的正式 GET /reusable-assets（0 DEV Mock）。
const assetOptions = useAssetOptions(createAssetOptionClient(http as unknown as AssetIndexTransport));
function onUpdateUnitAssets(unit: ImageBatchUnit, patch: Pick<ImageBatchUnit, 'character_asset_ids' | 'scene_asset_id'>): void {
  editor.value.updateUnitAssets(unit.id, patch);
}
watch(
  () => [props.projectId, props.itemId, props.branchId],
  () => {
    editor.value = useImageBatch(props.projectId, props.itemId, props.branchId);
    void editor.value.load();
    void assetOptions.load(props.projectId);
  },
  { immediate: true },
);
</script>

<template>
  <AdminLayout>
    <Breadcrumb
      :items="[
        { label: '内容库', href: '/projects/' + projectId + '/content' },
        { label: editor.workspace.value?.content_title ?? '图片批次', href: '/projects/' + projectId + '/content/' + itemId },
        { label: '图片批次生产' },
      ]"
    />
    <div class="mb-4">
      <Link :href="'/projects/' + projectId + '/content/' + itemId" class="text-sm text-slate-600 underline">返回篇目</Link>
    </div>
    <ReviewSubmission :project-id="projectId" :item-id="itemId" :branch-id="branchId" target="image_batch" :dirty="editor.reviewDirty.value" :busy='editor.busy.value' :ready="!!editor.workspace.value && !editor.loadError.value" />
    <ImageBatchWorkspaceBody
      :content-title="editor.workspace.value?.content_title ?? ''"
      :source-label="editor.workspace.value?.source_label ?? ''"
      :batch-instruction="editor.workspace.value?.batch_instruction ?? ''"
      :batch-note="editor.workspace.value?.batch_note ?? null"
      :assets="editor.workspace.value?.assets ?? []"
      :units="editor.units.value"
      :history="editor.workspace.value?.history ?? []"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :run-phase="editor.runPhase.value"
      :run-error="editor.runError.value"
      :copied="editor.copied.value"
      :history-open="editor.historyOpen.value"
      :all-selected="editor.allSelected.value"
      :changed-count="editor.changedUnits.value.length"
      :busy="editor.busy.value" :dirty="editor.dirty.value" :selected-unit-ids="editor.selectedUnitIds.value"
      :character-options="assetOptions.options.value.character"
      :scene-options="assetOptions.options.value.scene"
      :asset-options-loading="assetOptions.loading.value"
      :asset-manage-url="'/projects/' + projectId + '/reusable-assets'"
      @update:batch-instruction="editor.updateBatchInstruction($event)"
      @update:batch-note="editor.updateBatchNote($event)"
      @toggle-unit="editor.toggleUnit($event.id)"
      @select-all="editor.selectAll()"
      @clear-selection="editor.clearSelection()"
      @update-unit="editor.updateUnit($event.id, { special_requirement: $event.special_requirement })"
      @update-unit-assets="onUpdateUnitAssets"
      @run="editor.run($event)"
      @copy-task="editor.copyTask()"
      @save-draft="editor.saveDraft()"
      @toggle-history="editor.historyOpen.value = !editor.historyOpen.value"
      @view-change="editor.viewChange($event)"
      @keep-current="editor.keepCurrent($event.id)"
      @regenerate="editor.regenerate($event.id)"
      @back="router.visit('/projects/' + projectId + '/content/' + itemId)"
    />
  </AdminLayout>
</template>
