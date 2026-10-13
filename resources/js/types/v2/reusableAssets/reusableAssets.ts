// V2 人物 / 场景复用资产索引 —— 轻量前端数据合同（冻结）。
//
// 产品定位：这是「轻量复用索引」，不是素材管理平台 / DAM / 图库 / 文件管理器。
// 用户只需要快速知道：
//   这个人物是否已有稳定母版？这个场景是否已有稳定母版？缺什么？哪些可复用？
//
// 收录规则：
//   - 人物只收长期重复出现的固定身份（爸爸/妈妈/姐姐/弟弟/爷爷/奶奶）；
//     路人、一次性角色、背景人物不建 Character。
//   - 场景只保存「稳定母版」（客厅/儿童房/厨房/办公室）；
//     早晨/夜晚/下雨/多一个杯子的客厅、人物位置变化不进入 Scene Master。
//
// 不扩展 prompt / model / seed / workflow / approval / owner /
// folder tree / technical metadata 等任何制作与资产管理字段。

/** 资产类型：人物 / 场景。 */
export type ReusableAssetType = 'character' | 'scene';

/** 资产状态：只有已有 / 缺失两态（不引入制作状态机）。 */
export type ReusableAssetStatus = 'existing' | 'missing';

/** 状态筛选：全部 / 已有 / 缺失。 */
export type AssetStatusFilter = 'all' | ReusableAssetStatus;

/** 顶部 Tab（人物 / 场景）。 */
export type AssetTab = ReusableAssetType;

/** 一条复用资产（人物固定身份 或 场景稳定母版）。 */
export interface ReusableAssetItem {
  id: number | string;
  type: ReusableAssetType;
  name: string;
  status: ReusableAssetStatus;
  /** 参考图缩略图；缺失或未建立时为 null（卡片显示占位，不强制上传）。 */
  thumbnail_url: string | null;
  /** 简短说明（人物：外形/身份要点；场景：稳定母版说明）。 */
  description: string | null;
  /** 最近使用（ISO 时间）；从未使用为 null。 */
  last_used_at: string | null;
}

/** 复用索引工作负载：人物 / 场景两个分组。 */
export interface ReusableAssetWorkspace {
  characters: ReusableAssetItem[];
  scenes: ReusableAssetItem[];
}

/**
 * 「准备建立」事件名契约（第一阶段按钮只 emit，不调用 Work、不创建生成 API）：
 *   人物缺失 → emit('prepareCharacter', item)
 *   场景缺失 → emit('prepareScene', item)
 */
export const PREPARE_EVENT_BY_TYPE: Record<ReusableAssetType, 'prepareCharacter' | 'prepareScene'> = {
  character: 'prepareCharacter',
  scene: 'prepareScene',
};

/** 「查看」事件名契约（轻量查看，占位，不做复杂详情）。 */
export const VIEW_EVENT_BY_TYPE: Record<ReusableAssetType, 'viewCharacter' | 'viewScene'> = {
  character: 'viewCharacter',
  scene: 'viewScene',
};
