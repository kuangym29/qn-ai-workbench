// V2 图片批次生产 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责边界：
//   正式生图任务由后端组装执行；本模块【不实现】生图业务规则。
//   这里只提供：
//     1. 展示标签映射（执行方式 / 单页状态 / 资产状态）。
//     2. 资产摘要分组（人物 / 场景，整批聚合一次）。
//     3. 选择逻辑（全选 / 取消 / 执行目标推导）——基于前端本地 selectedUnitIds，
//        不使用服务端字段（R1 合同：ImageBatchUnit 不含 selected）。
//     4. 来源内容变化提示的判定与计数。
//     5. Draft 载荷生成（只包含用户可编辑的制作补充）。
//   DEV 预览 Mock 位于 imageBatchDevMock.ts（DEV ONLY），正式生产模块不引用。
import type {
  AssetCheck,
  AssetCheckStatus,
  AssetCheckType,
  ImageBatchDraftInput,
  ImageBatchExecutionMode,
  ImageBatchUnit,
  ImageBatchUnitStatus,
  ImageBatchWorkspace,
} from '../../../types/v2/imageBatch/imageBatch';

/** 页面类型中文标签（与 Foundation api/types PAGE_TYPE_LABELS 一致，内联避免运行时导入）。 */
const PAGE_TYPE_LABELS_LOCAL: Record<string, string> = {
  cover: '封面',
  content: '正文',
  column_closing: '栏目收尾',
  fixed_back_cover: '固定封底',
};

export function executionLabel(mode: ImageBatchExecutionMode): string {
  const labels: Record<ImageBatchExecutionMode, string> = {
    full: '整批生成',
    trial: '先做试样',
    partial: '选择部分页面生成',
  };
  return labels[mode];
}

export function unitStatusLabel(status: ImageBatchUnitStatus): string {
  const labels: Record<ImageBatchUnitStatus, string> = {
    pending: '待制作',
    generated: '已生成',
    needs_revision: '需修改',
    approved: '已通过',
  };
  return labels[status];
}

export function assetStatusLabel(status: AssetCheckStatus): string {
  const labels: Record<AssetCheckStatus, string> = {
    existing: '已有',
    missing: '缺失',
    new_in_batch: '本批新建',
  };
  return labels[status];
}

export function pageTypeLabel(pageType: string): string {
  return PAGE_TYPE_LABELS_LOCAL[pageType] ?? pageType;
}

/** 页面显示标签：P1 / P2… */
export function pageLabel(pageNo: number): string {
  return `P${pageNo}`;
}

/** 资产摘要：按人物 / 场景分组（保持输入顺序）。 */
export function groupAssets(assets: AssetCheck[]): { characters: AssetCheck[]; scenes: AssetCheck[] } {
  return {
    characters: assets.filter((a) => a.type === 'character'),
    scenes: assets.filter((a) => a.type === 'scene'),
  };
}

/** 已选择页面（按 page_no 升序）——selectedIds 为前端本地选择状态。 */
export function selectedUnits(units: ImageBatchUnit[], selectedIds: ReadonlySet<number | string>): ImageBatchUnit[] {
  return units.filter((u) => selectedIds.has(u.id)).sort((a, b) => a.page_no - b.page_no);
}

export function selectionSummary(
  units: ImageBatchUnit[],
  selectedIds: ReadonlySet<number | string>,
): { selected: number; total: number } {
  return { selected: selectedIds.size, total: units.length };
}

/**
 * 执行目标推导：
 *   - full：整批生成，忽略勾选，返回全部页面（unit_ids 可为空，由后端解释为整批）；
 *   - trial / partial：先做试样 / 选择部分页面生成，返回已勾选页面（可能为空，由 UI 提示）。
 */
export function executionTargets(
  units: ImageBatchUnit[],
  selectedIds: ReadonlySet<number | string>,
  mode: ImageBatchExecutionMode,
): ImageBatchUnit[] {
  if (mode === 'full') return [...units].sort((a, b) => a.page_no - b.page_no);
  return selectedUnits(units, selectedIds);
}

/**
 * Draft 载荷生成（PUT image-batch）：
 * 只发送用户可编辑的制作补充（batch_instruction / batch_note / 单页 special_requirement）。
 * 不要把 final_copy / visual_evidence / characters / scene / page_type 作为前端可写字段。
 */
export function buildDraftPayload(workspace: ImageBatchWorkspace, units: ImageBatchUnit[]): ImageBatchDraftInput {
  return {
    batch_instruction: workspace.batch_instruction,
    batch_note: workspace.batch_note,
    units: units.map((u) => ({ id: u.id, special_requirement: u.special_requirement })),
  };
}

/** 是否存在来源页后来有修改的页面。 */
export function hasSourceChange(units: ImageBatchUnit[]): boolean {
  return units.some((u) => u.source_changed === true);
}

/** 来源变化的页面（按 page_no 升序）。 */
export function sourceChangedUnits(units: ImageBatchUnit[]): ImageBatchUnit[] {
  return units.filter((u) => u.source_changed === true).sort((a, b) => a.page_no - b.page_no);
}

/** 历史批次是否为空。 */
export function hasHistory(history: { length: number } | null | undefined): boolean {
  return !!history && history.length > 0;
}

/** 批次状态（历史状态为后端字符串，原样展示；此处仅提供空值兜底）。 */
export function historyStatusLabel(status: string | null | undefined): string {
  return status && status.trim() ? status : '未知';
}
