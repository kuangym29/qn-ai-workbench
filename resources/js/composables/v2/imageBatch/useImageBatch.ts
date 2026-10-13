// V2 图片批次生产 —— 状态机 composable。
//
// 状态：
//   - 工作台（content_title / source_label / batch_instruction / batch_note /
//     assets / units / history）
//   - 加载 / 错误
//   - 选择（前端本地 selectedUnitIds，不属于服务端正式数据，reload 后清空为正常行为）
//   - 制作补充 dirty 追踪（batch_instruction / batch_note / 单页 special_requirement）
//   - 执行动作子状态：idle → running → done / error
//
// 规则（R1 合同，V2-P3-C05-R1）：
//   - ImageBatchUnit 不含 selected；勾选由本 composable 维护（selectedUnitIds Set）。
//   - 可编辑制作补充通过 PUT Draft 保存；run / copy-task 前若 dirty 先自动保存，
//     用户不需要额外点一次“保存”；Draft 保存失败不得继续 run / copy-task。
//   - copy-task 不接受前端拼好的 Prompt，由后端基于最新 Draft + 来源结构组装完整 Task。
//   - 来源变化决策（keep / regenerate）调用正式 source-change API，成功后重新 GET；
//     不乐观伪造服务端状态。
//   - 生产 composable 只负责 API transport / UI state；生图任务由后端组装。
import { computed, ref } from 'vue';
import { http } from '../../../api/http';
import type {
  ImageBatchExecutionMode,
  ImageBatchRunPhase,
  ImageBatchSaveState,
  ImageBatchUnit,
  ImageBatchWorkspace,
} from '../../../types/v2/imageBatch/imageBatch';
import {
  buildDraftPayload,
  executionTargets,
  hasSourceChange,
  selectedUnits,
  sourceChangedUnits,
} from './imageBatchCore';
import { createImageBatchClient, type ImageBatchClient } from './imageBatchApi';
import { firstErrorMessage } from '../../../api/errors';
import { toast } from '../../../ui/toast';

export type { ImageBatchRunPhase, ImageBatchSaveState } from '../../../types/v2/imageBatch/imageBatch';

export function useImageBatch(
  projectId: number | string,
  itemId: number | string,
  branchId: number | string,
  client: ImageBatchClient = createImageBatchClient(http),
) {
  const workspace = ref<ImageBatchWorkspace | null>(null);
  const loading = ref(true);
  const loadError = ref('');
  const runPhase = ref<ImageBatchRunPhase>('idle');
  const runError = ref('');
  const copied = ref(false);
  const historyOpen = ref(false);

  // 前端本地选择状态（不属于服务端正式数据；reload / 重新 GET 后清空为正常行为）。
  const selectedUnitIds = ref<Set<number | string>>(new Set());
  const selectedIdList = computed<readonly (number | string)[]>(() => [...selectedUnitIds.value]);

  // 制作补充保存状态。
  const dirty = ref(false);
  const saveState = ref<ImageBatchSaveState>('saved');

  const units = computed<ImageBatchUnit[]>(() => workspace.value?.units ?? []);

  const selection = computed(() => {
    const list = units.value;
    return {
      selected: selectedUnitIds.value.size,
      total: list.length,
    };
  });

  const allSelected = computed(
    () => units.value.length > 0 && units.value.every((u) => selectedUnitIds.value.has(u.id)),
  );

  const changedUnits = computed(() => sourceChangedUnits(units.value));
  const anySourceChange = computed(() => hasSourceChange(units.value));

  async function load(): Promise<boolean> {
    loading.value = true;
    loadError.value = '';
    try {
      workspace.value = await client.loadWorkspace(projectId, itemId, branchId);
      // 重新 GET 后选择清空（正常行为，选择不属于服务端状态）。
      selectedUnitIds.value = new Set();
      dirty.value = false;
      saveState.value = 'saved';
      return true;
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
      return false;
    } finally {
      loading.value = false;
    }
  }

  function markDirty(): void {
    dirty.value = true;
    saveState.value = 'dirty';
  }

  // ---- 整批说明 / 备注（轻量手动修正 → Draft） ----
  function updateBatchInstruction(text: string): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = { ...workspace.value, batch_instruction: text };
    markDirty();
  }

  function updateBatchNote(text: string): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = { ...workspace.value, batch_note: text };
    markDirty();
  }

  // ---- 页面选择（前端本地状态） ----
  function toggleUnit(id: number | string): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    const next = new Set(selectedUnitIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedUnitIds.value = next;
  }

  function selectAll(): void {
    if (!workspace.value || loading.value) return;
    selectedUnitIds.value = new Set(units.value.map((u) => u.id));
  }

  function clearSelection(): void {
    if (!workspace.value || loading.value) return;
    selectedUnitIds.value = new Set();
  }

  // ---- 单页轻量修正（特殊要求 → Draft；只允许编辑制作补充） ----
  function updateUnit(id: number | string, patch: { special_requirement: string | null }): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = {
      ...workspace.value,
      units: workspace.value.units.map((u) => (u.id === id ? { ...u, special_requirement: patch.special_requirement } : u)),
    };
    markDirty();
  }

  // ---- Draft 保存 ----
  /**
   * 保存整批制作 Draft（PUT image-batch）。
   * 成功：dirty 复位；失败：saveState = error，返回 false（调用方不得继续 run / copy-task）。
   * 注意：保存成功后不重新 GET（避免清空当前勾选）；run / source-change 成功后的 reload 才清空选择。
   */
  async function saveDraft(): Promise<boolean> {
    if (!workspace.value) return false;
    if (!dirty.value) return true;
    saveState.value = 'saving';
    try {
      await client.saveDraft(projectId, itemId, branchId, buildDraftPayload(workspace.value, units.value));
      dirty.value = false;
      saveState.value = 'saved';
      return true;
    } catch (e) {
      saveState.value = 'error';
      toast.error(firstErrorMessage(e) ?? '保存制作补充失败');
      return false;
    }
  }

  // ---- 执行动作 ----
  async function run(mode: ImageBatchExecutionMode): Promise<boolean> {
    if (!workspace.value || runPhase.value === 'running') return false;

    // dirty 时先自动保存 Draft；保存失败不得继续执行。
    if (dirty.value) {
      const saved = await saveDraft();
      if (!saved) {
        runError.value = '保存制作补充失败，未执行生成。';
        runPhase.value = 'error';
        return false;
      }
    }

    const targets = executionTargets(units.value, selectedUnitIds.value, mode);
    if (mode !== 'full' && targets.length === 0) {
      runError.value = '请先勾选至少一页，再执行试样或部分生成。';
      runPhase.value = 'error';
      return false;
    }
    runPhase.value = 'running';
    runError.value = '';
    try {
      await client.run(projectId, itemId, branchId, {
        mode,
        unit_ids: mode === 'full' ? [] : targets.map((u) => u.id),
      });
      runPhase.value = 'done';
      toast.success('批次任务已提交，等待系统组装与生成。');
      // 执行成功后刷新服务端结果（load 内会清空选择）
      await load();
      return true;
    } catch (e) {
      runError.value = firstErrorMessage(e) ?? '提交失败';
      runPhase.value = 'error';
      return false;
    }
  }

  /** 复制整批生图任务（正式链路由系统基于最新 Draft + 来源结构组装；本任务仅事件 + typed contract）。 */
  async function copyTask(): Promise<void> {
    if (!workspace.value || runPhase.value === 'running') return;
    // dirty 时先自动保存 Draft；保存失败不得继续。
    if (dirty.value) {
      const saved = await saveDraft();
      if (!saved) {
        toast.error('保存制作补充失败，未复制任务。');
        return;
      }
    }
    copied.value = false;
    try {
      await client.copyTask(projectId, itemId, branchId);
      copied.value = true;
      toast.success('已复制整批生图任务（系统将组装整批说明 + 最新页面结构）。');
    } catch (e) {
      toast.error(firstErrorMessage(e) ?? '复制任务失败');
    }
  }

  // ---- 来源变化 ----
  /** 查看修改：展开来源变化摘要（前端只展示系统检测结果）。 */
  function viewChange(unit: ImageBatchUnit): void {
    // 轻量交互：由 UI 直接读取 unit.source_change_summary，本函数保持可测试的状态入口。
    void unit;
  }

  /** 继续沿用：记录正式决策（keep）成功后重新 GET 工作台，不乐观伪造服务端状态。 */
  async function keepCurrent(unitId: number | string): Promise<boolean> {
    return resolveSourceChange(unitId, 'keep');
  }

  /** 重新生成：记录正式决策（regenerate）成功后重新 GET 工作台。 */
  async function regenerate(unitId: number | string): Promise<boolean> {
    return resolveSourceChange(unitId, 'regenerate');
  }

  async function resolveSourceChange(unitId: number | string, action: 'keep' | 'regenerate'): Promise<boolean> {
    if (!workspace.value || runPhase.value === 'running') return false;
    try {
      await client.resolveSourceChange(projectId, itemId, branchId, unitId, { action });
      // 成功后重新 GET 工作台（服务端返回最新来源变化状态）
      await load();
      return true;
    } catch (e) {
      toast.error(firstErrorMessage(e) ?? '记录来源变化决策失败');
      return false;
    }
  }

  return {
    workspace,
    loading,
    loadError,
    runPhase,
    runError,
    copied,
    historyOpen,
    units,
    selectedUnitIds,
    selectedIdList,
    dirty,
    saveState,
    selection,
    allSelected,
    changedUnits,
    anySourceChange,
    load,
    updateBatchInstruction,
    updateBatchNote,
    toggleUnit,
    selectAll,
    clearSelection,
    updateUnit,
    saveDraft,
    run,
    copyTask,
    viewChange,
    keepCurrent,
    regenerate,
  };
}
