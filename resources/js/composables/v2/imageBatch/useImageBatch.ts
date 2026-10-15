import { computed, ref } from 'vue';
import { http } from '../../../api/http';
import type { ImageBatchExecutionMode, ImageBatchUnit, ImageBatchWorkspace, ImageBatchDraftInput, ImageBatchRunPhase } from '../../../types/v2/imageBatch/imageBatch';
import { executionTargets, selectionSummary, sourceChangedUnits } from './imageBatchCore';
import { createImageBatchClient, type ImageBatchClient } from './imageBatchApi';
import { firstErrorMessage } from '../../../api/errors';

export function useImageBatch(
  projectId: number | string, itemId: number | string, branchId: number | string,
  client: ImageBatchClient = createImageBatchClient(http),
  copyText: (text: string) => Promise<void> = text => navigator.clipboard.writeText(text),
) {
  const workspace = ref<ImageBatchWorkspace | null>(null);
  const selectedUnitIds = ref<Set<number | string>>(new Set());
  const dirty = ref(false);
  const savedReviewInput = ref('');
  const reviewInput = () => JSON.stringify(workspace.value ? {
    batch_instruction: workspace.value.batch_instruction, batch_note: workspace.value.batch_note,
    units: workspace.value.units.map(u => ({ id: u.id, special_requirement: u.special_requirement })),
  } : null);
  const reviewDirty = computed(() => workspace.value !== null && reviewInput() !== savedReviewInput.value);
  const loading = ref(false);
  const saving = ref(false);
  const copying = ref(false);
  const resolving = ref(false);
  const loadError = ref('');
  const runError = ref('');
  const runPhase = ref<ImageBatchRunPhase>('idle');
  const copied = ref(false);
  const historyOpen = ref(false);
  const busy = computed(() => loading.value || saving.value || copying.value || resolving.value || runPhase.value === 'running');
  const units = computed(() => workspace.value?.units ?? []);
  const selection = computed(() => selectionSummary(units.value, selectedUnitIds.value));
  const allSelected = computed(() => units.value.length > 0 && selection.value.selected === units.value.length);
  const changedUnits = computed(() => sourceChangedUnits(units.value));
  const anySourceChange = computed(() => changedUnits.value.length > 0);

  // Internal GET stays under the enclosing action lock; public reload cannot race actions.
  async function fetchWorkspace(): Promise<boolean> {
    loading.value = true; loadError.value = '';
    try {
      workspace.value = await client.loadWorkspace(projectId, itemId, branchId);
      selectedUnitIds.value = new Set(); dirty.value = false;
      savedReviewInput.value = reviewInput();
      return true;
    } catch (e) { loadError.value = firstErrorMessage(e) ?? '加载失败'; return false; }
    finally { loading.value = false; }
  }
  async function load(): Promise<boolean> { return busy.value ? false : fetchWorkspace(); }
  function updateBatchInstruction(text: string): void {
    if (!workspace.value || busy.value) return;
    workspace.value.batch_instruction = text; dirty.value = true; copied.value = false;
  }
  function updateBatchNote(text: string): void {
    if (!workspace.value || busy.value) return;
    workspace.value.batch_note = text || null; dirty.value = true; copied.value = false;
  }
  function updateUnit(id: number | string, patch: Pick<ImageBatchUnit, 'special_requirement'>): void {
    if (!workspace.value || busy.value) return;
    const unit = units.value.find(u => u.id === id);
    if (!unit) return;
    unit.special_requirement = patch.special_requirement;
    dirty.value = true; copied.value = false;
  }
  function toggleUnit(id: number | string): void {
    if (!workspace.value || busy.value || !units.value.some(u => u.id === id)) return;
    const next = new Set(selectedUnitIds.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    selectedUnitIds.value = next;
  }
  function selectAll(): void { if (!busy.value) selectedUnitIds.value = new Set(units.value.map(u => u.id)); }
  function clearSelection(): void { if (!busy.value) selectedUnitIds.value = new Set(); }
  async function persistDraft(): Promise<void> {
    if (!workspace.value || !dirty.value) return;
    const input: ImageBatchDraftInput = {
      batch_instruction: workspace.value.batch_instruction, batch_note: workspace.value.batch_note,
      units: units.value.map(u => ({ id: u.id, special_requirement: u.special_requirement })),
    };
    saving.value = true;
    try { await client.saveDraft(projectId, itemId, branchId, input); dirty.value = false; }
    catch (e) { throw new Error('草稿保存失败：' + (firstErrorMessage(e) ?? '请重试')); }
    finally { saving.value = false; }
  }
  async function saveDraft(): Promise<boolean> {
    if (!workspace.value || busy.value) return false;
    runError.value = '';
    const selected = new Set(selectedUnitIds.value);
    try {
      await persistDraft(); saving.value = true;
      if (!await fetchWorkspace()) { runError.value = '草稿已保存，但刷新失败，请重新加载。'; return false; }
      selectedUnitIds.value = new Set(units.value.filter(u => selected.has(u.id)).map(u => u.id));
      return true;
    }
    catch (e) { runError.value = firstErrorMessage(e) ?? '草稿保存失败'; return false; }
    finally { saving.value = false; }
  }
  async function run(mode: ImageBatchExecutionMode): Promise<boolean> {
    if (!workspace.value || busy.value) return false;
    const targets = executionTargets(units.value, mode, selectedUnitIds.value);
    if (mode !== 'full' && !targets.length) { runError.value = '请先勾选至少一页，再执行试样或部分生成。'; runPhase.value = 'error'; return false; }
    runPhase.value = 'running'; runError.value = '';
    try {
      await persistDraft();
      await client.run(projectId, itemId, branchId, { mode, unit_ids: mode === 'full' ? [] : targets.map(u => u.id) });
      if (!await fetchWorkspace()) runError.value = '任务已提交，但刷新失败，请重新加载查看结果。';
      runPhase.value = 'done'; return true;
    } catch (e) { runError.value = firstErrorMessage(e) ?? '提交失败'; runPhase.value = 'error'; return false; }
  }
  async function copyTask(): Promise<boolean> {
    if (!workspace.value || busy.value) return false;
    copying.value = true; copied.value = false; runError.value = '';
    try {
      await persistDraft();
      const result = await client.copyTask(projectId, itemId, branchId);
      if (typeof result.task !== 'string' || !result.task.trim()) throw new Error('服务端未返回可复制的任务文本');
      await copyText(result.task); copied.value = true; return true;
    } catch (e) { runError.value = firstErrorMessage(e) ?? '复制任务失败'; return false; }
    finally { copying.value = false; }
  }
  async function resolveSourceChange(id: number | string, action: 'keep' | 'regenerate'): Promise<boolean> {
    if (!workspace.value || busy.value) return false;
    resolving.value = true; runError.value = '';
    try {
      // The following GET must not discard user-authored Draft fields.
      await persistDraft();
      await client.resolveSourceChange(projectId, itemId, branchId, id, { action });
      if (!await fetchWorkspace()) runError.value = '来源决策已保存，但刷新失败，请重新加载。';
      return true;
    } catch (e) { runError.value = firstErrorMessage(e) ?? '来源决策保存失败'; return false; }
    finally { resolving.value = false; }
  }
  function viewChange(unit: ImageBatchUnit): void { void unit; }
  const keepCurrent = (id: number | string) => resolveSourceChange(id, 'keep');
  const regenerate = (id: number | string) => resolveSourceChange(id, 'regenerate');
  return { workspace, selectedUnitIds, dirty, reviewDirty, loading, saving, copying, resolving, busy, loadError, runPhase, runError, copied, historyOpen,
    units, selection, allSelected, changedUnits, anySourceChange, load, saveDraft, updateBatchInstruction, updateBatchNote,
    toggleUnit, selectAll, clearSelection, updateUnit, run, copyTask, viewChange, keepCurrent, regenerate };
}
