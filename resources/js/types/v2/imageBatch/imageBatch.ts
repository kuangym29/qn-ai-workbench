// V2 图片批次生产 —— 数据合同类型。
//
// 产品原则：图片生产以「整批」为主。
// 核心操作只回答：
//   1. 这一整批图怎么做？
//   2. 有没有整批特殊备注？
//   3. 系统拆出来的每一页是否正确？
//   4. 这次我要整批做、先试样，还是只做部分页面？
//
// 本任务只实现 UI / typed contract / event，不开发真实生图后端。
// 正式后端尚未完成：本类型为冻结展示合同；生产 API adapter 独立于 DEV Mock。
import type { PageType } from '../../../api/types';

/** 执行方式：整批生成 / 先做试样 / 选择部分页面生成。 */
export type ImageBatchExecutionMode = 'full' | 'trial' | 'partial';

/** 单页轻量状态：待制作 / 已生成 / 需修改 / 已通过。 */
export type ImageBatchUnitStatus = 'pending' | 'generated' | 'needs_revision' | 'approved';

/** 资产检查类型：人物 / 场景。 */
export type AssetCheckType = 'character' | 'scene';

/** 资产状态：已有 / 缺失 / 本批新建。 */
export type AssetCheckStatus = 'existing' | 'missing' | 'new_in_batch';

/** 批次资产检查条目（整批聚合一次，不做逐页重复提醒，不做复杂 DAM）。 */
export interface AssetCheck {
  type: AssetCheckType;
  name: string;
  status: AssetCheckStatus;
}

/** 页面单元：系统从内容结构自动得到，用户主要查看 / 轻量修正 / 选择是否参与本次生成。 */
export interface ImageBatchUnit {
  id: number | string;
  page_id: number;
  page_no: number;
  page_type: PageType;
  /** 最终文字（弱展示） */
  final_copy: string | null;
  /** 画面证据 */
  visual_evidence: string | null;
  characters: string[];
  scene: string | null;
  special_requirement: string | null;
  /** 是否参与本次生成（整批生成忽略该标记） */
  selected: boolean;
  status: ImageBatchUnitStatus;
  /** 来源页后来有修改（由系统检测，前端只展示） */
  source_changed?: boolean;
  source_change_summary?: string | null;
}

/** 历史批次（轻量入口：创建时间 / 包含页面 / 状态 / 说明摘要）。 */
export interface BatchHistoryItem {
  id: number | string;
  created_at: string;
  page_labels: string[];
  status: string;
  instruction_summary: string | null;
}

/** 图片批次工作台。 */
export interface ImageBatchWorkspace {
  content_title: string;
  source_label: string;
  /** 整批生图说明（一个大文本区，不拆字段） */
  batch_instruction: string;
  /** 整批备注（弱化文本区） */
  batch_note: string | null;
  /** 批次资产检查摘要 */
  assets: AssetCheck[];
  units: ImageBatchUnit[];
  history: BatchHistoryItem[];
}

/** 执行动作输入：mode + 参与页面（full 忽略 unit_ids，传空数组即可）。 */
export interface ImageBatchRunInput {
  mode: ImageBatchExecutionMode;
  unit_ids: (number | string)[];
}

/** 执行动作结果（状态以后端为准）。 */
export interface ImageBatchRunResult {
  batch_id: number | string | null;
  mode: ImageBatchExecutionMode;
  unit_ids: (number | string)[];
  status: string;
}

// ---------------------------------------------------------------------------
// 展示标签
// ---------------------------------------------------------------------------

export const IMAGE_BATCH_EXECUTION_LABELS: Record<ImageBatchExecutionMode, string> = {
  full: '整批生成',
  trial: '先做试样',
  partial: '选择部分页面生成',
};

export const IMAGE_BATCH_UNIT_STATUS_LABELS: Record<ImageBatchUnitStatus, string> = {
  pending: '待制作',
  generated: '已生成',
  needs_revision: '需修改',
  approved: '已通过',
};

export const ASSET_CHECK_STATUS_LABELS: Record<AssetCheckStatus, string> = {
  existing: '已有',
  missing: '缺失',
  new_in_batch: '本批新建',
};

/** 单页状态 badge 视觉层级（用于 UI 配色选择，不做业务判断）。 */
export const IMAGE_BATCH_UNIT_STATUS_TONE: Record<ImageBatchUnitStatus, 'slate' | 'emerald' | 'amber' | 'blue'> = {
  pending: 'slate',
  generated: 'emerald',
  needs_revision: 'amber',
  approved: 'blue',
};
