// V2 人物 / 场景复用资产索引 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责：
//   1. getItemsByType()      按 Tab 取分组
//   2. filterItems()         已有 / 缺失筛选
//   3. countByStatus()       已有 / 缺失计数（Tab 与筛选行使用）
//   4. statusLabel()         状态中文展示
//   5. formatLastUsed()      最近使用展示（null → 未使用；ISO → YYYY-MM-DD）
//   6. groupByType()         按 type 分组（防御异常数据）
//   7. normalizeOptionalText() / isAssetNameValid() / buildAssetCreatePayload() /
//      buildAssetUpdatePayload()  —— P5-I02 新增 / 编辑表单的归一与载荷构造
import type {
  AssetEditorForm,
  AssetStatusFilter,
  AssetTab,
  ReusableAssetCreatePayload,
  ReusableAssetItem,
  ReusableAssetStatus,
  ReusableAssetType,
  ReusableAssetUpdatePayload,
  ReusableAssetWorkspace,
} from '../../../types/v2/reusableAssets/reusableAssets';

const STATUS_LABELS: Record<ReusableAssetStatus, string> = {
  existing: '已有',
  missing: '缺失',
};

/** 按 Tab 取分组（保持服务端顺序）。 */
export function getItemsByType(ws: ReusableAssetWorkspace, tab: AssetTab): ReusableAssetItem[] {
  return tab === 'character' ? ws.characters : ws.scenes;
}

/** 按状态筛选（all 不过滤）。 */
export function filterItems(
  items: ReusableAssetItem[],
  filter: AssetStatusFilter,
): ReusableAssetItem[] {
  if (filter === 'all') return items;
  return items.filter((item) => item.status === filter);
}

/** 已有 / 缺失计数。 */
export function countByStatus(items: ReusableAssetItem[]): { existing: number; missing: number; total: number } {
  let existing = 0;
  let missing = 0;
  for (const item of items) {
    if (item.status === 'existing') existing++;
    else if (item.status === 'missing') missing++;
  }
  return { existing, missing, total: items.length };
}

/** 状态中文展示。 */
export function statusLabel(status: ReusableAssetStatus): string {
  return STATUS_LABELS[status] ?? status;
}

/**
 * 最近使用展示：
 *   null / 空字符串 → 「未使用」
 *   ISO 字符串 → 取日期部分 YYYY-MM-DD（不做复杂相对时间，保持轻量）
 */
export function formatLastUsed(iso: string | null): string {
  if (!iso) return '未使用';
  const datePart = iso.slice(0, 10);
  // 形如 2026-09-30 才视为有效日期，否则原样展示（防御）。
  return /^\d{4}-\d{2}-\d{2}$/.test(datePart) ? datePart : iso;
}

/** 按 type 把任意资产列表分组（防御性：忽略无法识别的 type）。 */
export function groupByType(
  items: ReusableAssetItem[],
): Record<ReusableAssetType, ReusableAssetItem[]> {
  const characters: ReusableAssetItem[] = [];
  const scenes: ReusableAssetItem[] = [];
  for (const item of items) {
    if (item.type === 'character') characters.push(item);
    else if (item.type === 'scene') scenes.push(item);
  }
  return { character: characters, scene: scenes };
}

// ── P5-I02 表单归一 / 载荷构造（纯函数，可被 node:test 直接测试） ──────

/**
 * 可选文本归一：
 *   前后空白裁掉；空字符串 → null（= 清除，不是「不修改」）。
 * 后端把 null 视为清空，因此「用户清空输入框」能真实清掉预览图 / 说明。
 */
export function normalizeOptionalText(value: string | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/** 判断用户是否填了有效名称（创建时必填）。 */
export function isAssetNameValid(value: string): boolean {
  return value.trim() !== '';
}

/** 由弹层表单构造「新增」载荷；类型由当前 Tab 决定，客户端不提交 id / project_id / last_used_at。 */
export function buildAssetCreatePayload(
  type: ReusableAssetType,
  form: AssetEditorForm,
): ReusableAssetCreatePayload {
  return {
    type,
    name: form.name.trim(),
    status: form.status,
    thumbnail_url: normalizeOptionalText(form.thumbnail_url),
    description: normalizeOptionalText(form.description),
  };
}

/**
 * 由弹层表单构造「编辑」载荷；只含 status / thumbnail_url / description。
 * 一律带上显式值（含 null），这样「清空」才会真正下发；name / type 绝不提交。
 */
export function buildAssetUpdatePayload(form: AssetEditorForm): ReusableAssetUpdatePayload {
  return {
    status: form.status,
    thumbnail_url: normalizeOptionalText(form.thumbnail_url),
    description: normalizeOptionalText(form.description),
  };
}
