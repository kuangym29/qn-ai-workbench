// V2 图片批次生产 —— 状态机 composable。
//
// 状态：
//   - 工作台（content_title / source_label / batch_instruction / batch_note /
//     assets / units / history）
//   - 加载 / 错误
//   - 选择（全选 / 取消 / 单页勾选）
//   - 执行动作子状态：idle → running → done / error
//
// 规则：
//   - 生产 composable 只负责 API transport / UI state；
//   - 生图任务由后端组装，前端不自己整理 Prompt；
//   - 执行成功后以服务端返回刷新工作台，不乐观伪造服务端状态。
import { computed, ref } from 'vue';
import { http } from '../../../api/http';
import type {
  ImageBatchExecutionMode,
  ImageBatchUnit,
  ImageBatchWorkspace,
} from '../../types/v2/imageBatch/imageBatch';
import { executionTargets, hasSourceChange, selectedUnits, sourceChangedUnits } from './imageBatchCore';
import { createImageBatchClient, type ImageBatchClient } from './imageBatchApi';
import { firstErrorMessage } from '../../../api/errors';
import { toast } from '../../../ui/toast';

export type ImageBatchRunPhase = 'idle' | 'running' | 'done' | 'error';

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

  const units = computed<ImageBatchUnit[]>(() => workspace.value?.units ?? []);

  const selection = computed(() => {
    const list = units.value;
    return {
      selected: list.filter((u) => u.selected).length,
      total: list.length,
    };
  });

  const allSelected = computed(() => units.value.length > 0 && units.value.every((u) => u.selected));

  const changedUnits = computed(() => sourceChangedUnits(units.value));
  const anySourceChange = computed(() => hasSourceChange(units.value));

  async function load(): Promise<boolean> {
    loading.value = true;
    loadError.value = '';
    try {
      workspace.value = await client.loadWorkspace(projectId, itemId, branchId);
      return true;
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
      return false;
    } finally {
      loading.value = false;
    }
  }

  // ---- 整批说明 / 备注（轻量手动修正） ----
  function updateBatchInstruction(text: string): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = { ...workspace.value, batch_instruction: text };
  }

  function updateBatchNote(text: string): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = { ...workspace.value, batch_note: text };
  }

  // ---- 页面选择 ----
  function toggleUnit(id: number | string): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = {
      ...workspace.value,
      units: workspace.value.units.map((u) =>
        u.id === id ? { ...u, selected: !u.selected } : u,
      ),
    };
  }

  function selectAll(): void {
    if (!workspace.value || loading.value) return;
    workspace.value = {
      ...workspace.value,
      units: workspace.value.units.map((u) => ({ ...u, selected: true })),
    };
  }

  function clearSelection(): void {
    if (!workspace.value || loading.value) return;
    workspace.value = {
      ...workspace.value,
      units: workspace.value.units.map((u) => ({ ...u, selected: false })),
    };
  }

  // ---- 单页轻量修正（特殊要求等） ----
  function updateUnit(id: number | string, patch: Partial<ImageBatchUnit>): void {
    if (!workspace.value || loading.value || runPhase.value === 'running') return;
    workspace.value = {
      ...workspace.value,
      units: workspace.value.units.map((u) => (u.id === id ? { ...u, ...patch } : u)),
    };
  }

  // ---- 执行动作 ----
  async function run(mode: ImageBatchExecutionMode): Promise<boolean> {
    if (!workspace.value || runPhase.value === 'running') return false;
    const targets = executionTargets(units.value, mode);
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
      // 执行成功后刷新服务端结果
      await load();
      return true;
    } catch (e) {
      runError.value = firstErrorMessage(e) ?? '提交失败';
      runPhase.value = 'error';
      return false;
    }
  }

  /** 复制整批生图任务（正式链路由系统组装；本任务仅事件 + typed contract）。 */
  async function copyTask(): Promise<void> {
    if (!workspace.value || runPhase.value === 'running') return;
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

  /** 继续沿用：保留当前页，忽略来源变化（仅本地标记，正式决策由后端记录）。 */
  function keepCurrent(unitId: number | string): void {
    updateUnit(unitId, { source_changed: false, source_change_summary: null });
  }

  /** 重新生成：标记该页需重新生成（由后端记录正式决策）。 */
  function regenerate(unitId: number | string): void {
    updateUnit(unitId, { status: 'needs_revision' });
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
    run,
    copyTask,
    viewChange,
    keepCurrent,
    regenerate,
  };
}
