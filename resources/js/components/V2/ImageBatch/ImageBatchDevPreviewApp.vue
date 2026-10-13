<script setup lang="ts">
// V2 图片批次 —— DEV 预览 App（DEV ONLY：注入 Mock client，展示完整工作台）。
// 正式页面为 ImageBatchEditor.vue，数据由 WorkBuddy Phase 2 后端接线后提供。
import { onMounted, shallowRef, ref } from 'vue';
import { useImageBatch } from '../../../composables/v2/imageBatch/useImageBatch';
import { buildMockWorkspace, applyMockRun, applyMockCopyTask, saveMockDraft, resolveMockSourceChange } from '../../../composables/v2/imageBatch/imageBatchDevMock';
import type { ImageBatchDraftInput, ImageBatchSourceChangeInput } from '../../../types/v2/imageBatch/imageBatch';
import ImageBatchWorkspaceBody from './ImageBatchWorkspaceBody.vue';

const failSave = ref(false);
const events = ref<string[]>([]);
const pause = () => new Promise(resolve => setTimeout(resolve, 400));
// DEV ONLY: record contract calls and simulate failure at the transport boundary.
const devClient = {
  async loadWorkspace() {
    events.value.push('GET workspace'); await pause();
    return buildMockWorkspace();
  },
  async run(_p: number | string, _i: number | string, _b: number | string, input: { mode: 'full' | 'trial' | 'partial'; unit_ids: (number | string)[] }) {
    events.value.push('POST run ' + JSON.stringify(input)); await pause();
    return applyMockRun(input);
  },
  async copyTask() {
    events.value.push('POST copy-task'); await pause();
    return applyMockCopyTask();
  },
  async saveDraft(_p: number | string, _i: number | string, _b: number | string, input: ImageBatchDraftInput) {
    events.value.push('PUT Draft' + (failSave.value ? ' FAILED' : '') + ' ' + JSON.stringify(input)); await pause();
    if (failSave.value) throw new Error('DEV 模拟保存失败');
    await saveMockDraft(input);
  },
  async resolveSourceChange(_p: number | string, _i: number | string, _b: number | string, id: number | string, input: ImageBatchSourceChangeInput) {
    events.value.push('POST source-change ' + String(id) + ' ' + JSON.stringify(input)); await pause();
    await resolveMockSourceChange(id, input);
  },
};

const editor = shallowRef(useImageBatch(1, 1, 1, devClient));

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
    <div class="mb-4 flex flex-wrap gap-3 text-xs text-slate-600">
      <label><input v-model="failSave" type="checkbox" :disabled="editor.busy.value"> 模拟 Draft 保存失败</label>
      <button :disabled="editor.busy.value" @click="editor.load()">重新 GET</button>
      <details class="w-full"><summary>DEV 请求记录</summary><pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all">{{ events.join('\n') }}</pre></details>
    </div>
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
