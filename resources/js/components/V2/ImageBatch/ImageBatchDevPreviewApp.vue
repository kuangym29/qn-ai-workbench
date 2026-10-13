<script setup lang="ts">
// V2 图片批次 —— DEV 预览 App（DEV ONLY：注入 Mock client，展示完整工作台）。
// 正式页面为 ImageBatchEditor.vue，数据由 WorkBuddy Phase 2 后端接线后提供。
import { onMounted, shallowRef } from 'vue';
import { useImageBatch } from '../../../composables/v2/imageBatch/useImageBatch';
import { buildMockWorkspace, applyMockRun, applyMockCopyTask } from '../../../composables/v2/imageBatch/imageBatchDevMock';
import ImageBatchWorkspaceBody from './ImageBatchWorkspaceBody.vue';

// DEV ONLY Mock client（与生产 imageBatchApi 同形状，仅用于浏览器预览）。
const devClient = {
  async loadWorkspace() {
    return buildMockWorkspace();
  },
  async run(_p: number | string, _i: number | string, _b: number | string, input: { mode: 'full' | 'trial' | 'partial'; unit_ids: (number | string)[] }) {
    return applyMockRun(input);
  },
  async copyTask() {
    return applyMockCopyTask();
  },
};

const editor = shallowRef(useImageBatch(1, 1, 1, devClient as never));

onMounted(() => {
  void editor.value.load();
});
</script>

<template>
  <div class="min-h-screen bg-slate-100 px-4 py-6">
    <p class="mb-4 text-xs text-slate-500">
      DEV PREVIEW：固定示例数据，执行动作为内存 Mock（正式由后端 API 组装，POST 成功后刷新工作台）；正式页面为
      ImageBatchEditor.vue。
    </p>
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
      :busy="editor.runPhase.value === 'running'"
      @update:batch-instruction="editor.updateBatchInstruction($event)"
      @update:batch-note="editor.updateBatchNote($event)"
      @toggle-unit="editor.toggleUnit($event.id)"
      @select-all="editor.selectAll()"
      @clear-selection="editor.clearSelection()"
      @update-unit="editor.updateUnit($event.id, { special_requirement: $event.special_requirement })"
      @run="editor.run($event)"
      @copy-task="editor.copyTask()"
      @toggle-history="editor.historyOpen.value = !editor.historyOpen.value"
      @view-change="editor.viewChange($event)"
      @keep-current="editor.keepCurrent($event.id)"
      @regenerate="editor.regenerate($event.id)"
      @back="() => {}"
    />
  </div>
</template>
