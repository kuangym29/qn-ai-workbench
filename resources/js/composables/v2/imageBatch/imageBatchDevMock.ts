// V2 图片批次生产 —— DEV 预览 Mock 提供者（DEV ONLY，独立于正式生产模块）。
//
// 正式生产 composable（imageBatchCore / imageBatchApi / useImageBatch）绝不引用本文件。
// 本文件只被：
//   - ImageBatchDevPreviewApp.vue（浏览器开发预览）
//   - imageBatchDevMock.test.ts（DEV 展示测试）
// 引用。正式路径只能：工作台 / Draft 保存 / 执行动作 / 来源变化决策全部由后端 API 提供。
//
// R1 合同（V2-P3-C05-R1）：
//   - ImageBatchUnit 不含 selected（勾选为前端本地状态）；
//   - Mock 支持 saveDraft / run / copyTask / resolveSourceChange；
//   - 来源变化决策（keep / regenerate）内存模拟后重新 GET 返回更新后的工作台。
import type {
  AssetCheck,
  BatchHistoryItem,
  ImageBatchDraftInput,
  ImageBatchRunInput,
  ImageBatchRunResult,
  ImageBatchUnit,
  ImageBatchWorkspace,
  SourceChangeAction,
} from '../../../types/v2/imageBatch/imageBatch';

interface MockState {
  workspace: ImageBatchWorkspace;
  history: BatchHistoryItem[];
  /** 最近一次保存的 Draft（内存模拟，DEV ONLY）。 */
  draft: ImageBatchDraftInput | null;
}

function iso(daysAgo: number, hour: number): string {
  const d = new Date('2026-10-13T09:00:00+08:00');
  d.setDate(d.getDate() - daysAgo);
  d.setHours(hour, 30, 0, 0);
  return d.toISOString();
}

function buildMockUnits(): ImageBatchUnit[] {
  const base = [
    { page_no: 1, page_type: 'cover' as const, final_copy: '孩子说“我自己来”时，先别急着帮', visual_evidence: '妈妈在门口微微弯腰，视线平齐，等待孩子自己系鞋带' },
    { page_no: 2, page_type: 'content' as const, final_copy: '出门前，孩子总有自己的节奏。', visual_evidence: '清晨玄关，孩子坐在地上穿鞋，妈妈站在一旁' },
    { page_no: 3, page_type: 'content' as const, final_copy: '你把鞋带绕了一圈又一圈，小手笨拙却很认真。', visual_evidence: '特写：孩子的小手绕鞋带，鞋带交叉' },
    { page_no: 4, page_type: 'content' as const, final_copy: '你抬头，眼睛亮亮地说：“我系好了！”', visual_evidence: '孩子仰头看妈妈，嘴角上扬，鞋带已系好' },
    { page_no: 5, page_type: 'content' as const, final_copy: '等一等，是给孩子练习的空间。', visual_evidence: '妈妈蹲下与孩子平视，表情温和' },
    { page_no: 6, page_type: 'content' as const, final_copy: '你肯听，情绪就有了出口。', visual_evidence: '客厅沙发，孩子抱着玩具坐妈妈腿上' },
    { page_no: 7, page_type: 'content' as const, final_copy: '下一次，他还会自己来。', visual_evidence: '孩子背好小书包，自己拉开门' },
    { page_no: 8, page_type: 'content' as const, final_copy: '你等的这一会儿，是她自己来的底气。', visual_evidence: '门口逆光，孩子迈出门，妈妈在身后微笑' },
    { page_no: 9, page_type: 'content' as const, final_copy: '别急着帮，孩子正在长大。', visual_evidence: '小区楼下，孩子自己走在前，妈妈跟在几步后' },
    { page_no: 10, page_type: 'column_closing' as const, final_copy: '你在身边，“我自己来”更有底气。', visual_evidence: '黄昏，母子并肩走在回家路上' },
  ].map<ImageBatchUnit>((p, idx) => ({
    id: `u${p.page_no}`,
    page_id: 100 + idx,
    page_no: p.page_no,
    page_type: p.page_type,
    final_copy: p.final_copy,
    visual_evidence: p.visual_evidence,
    characters: p.page_no === 3 ? ['姐姐'] : ['妈妈', '姐姐'],
    scene: p.page_no <= 4 ? '客厅' : p.page_no <= 8 ? '玄关门口' : '小区楼下',
    special_requirement: p.page_no === 4 ? '表情特写，眼睛要亮' : p.page_no === 10 ? '黄昏暖光，逆光剪影' : null,
    status: p.page_no <= 2 ? ('generated' as const) : p.page_no === 3 ? ('needs_revision' as const) : ('pending' as const),
    source_changed: p.page_no === 4 || p.page_no === 5 ? true : false,
    source_change_summary: p.page_no === 4 ? '最终文字有更新：补充了“眼睛亮亮地”的表述。' : p.page_no === 5 ? '最终文字微调：把“等一等”提前到句首。' : null,
  }));
  return base;
}

function buildMockAssets(): AssetCheck[] {
  return [
    { type: 'character', name: '妈妈', status: 'existing' },
    { type: 'character', name: '爸爸', status: 'existing' },
    { type: 'character', name: '姐姐', status: 'missing' },
    { type: 'scene', name: '客厅', status: 'existing' },
    { type: 'scene', name: '儿童房', status: 'new_in_batch' },
  ];
}

function buildMockHistory(): BatchHistoryItem[] {
  return [
    {
      id: 'batch-2',
      created_at: iso(1, 14),
      page_labels: ['P4', 'P5'],
      status: '已通过',
      instruction_summary: '重做 P4/P5：表情特写 + 玄关门口光线。',
    },
    {
      id: 'batch-1',
      created_at: iso(3, 10),
      page_labels: ['P1', 'P2', 'P3'],
      status: '需修改',
      instruction_summary: '首批试样：封面 / 玄关系鞋带 / 手部特写。',
    },
  ];
}

function buildWorkspaceData(): ImageBatchWorkspace {
  return {
    content_title: '孩子说“我自己来”时，先别急着帮',
    source_label: '生活小能力 › 出门前的等待',
    batch_instruction: '整组采用温暖柔和的家庭日常摄影风：\n人物保持一致——妈妈（约30岁，中长发，浅色家居服）、姐姐（学龄前，双马尾）；\n场景保持一致——玄关与客厅的自然光，暖白基调；\n整体光线明亮柔和，转场自然，孩子动作真实，不做夸张表情。',
    batch_note: '本批重点：P4 需要“眼睛亮亮地”的特写表情；P10 收尾页用黄昏暖光。',
    assets: buildMockAssets(),
    units: buildMockUnits(),
    history: buildMockHistory(),
  };
}

/** 当前 mock 内存状态（DEV ONLY）。 */
export const mockImageBatchStore: MockState = {
  workspace: buildWorkspaceData(),
  history: buildMockHistory(),
  draft: null,
};

/** DEV ONLY MOCK —— 拉取批次工作台。 */
export async function buildMockWorkspace(): Promise<ImageBatchWorkspace> {
  return {
    ...mockImageBatchStore.workspace,
    units: mockImageBatchStore.workspace.units.map((u) => ({ ...u })),
    history: mockImageBatchStore.history.map((h) => ({ ...h })),
  };
}

/** DEV ONLY MOCK —— 保存整批制作 Draft（内存更新工作台并记录 draft）。 */
export async function applyMockSaveDraft(input: ImageBatchDraftInput): Promise<{ ok: boolean }> {
  mockImageBatchStore.workspace = {
    ...mockImageBatchStore.workspace,
    batch_instruction: input.batch_instruction,
    batch_note: input.batch_note,
    units: mockImageBatchStore.workspace.units.map((u) => {
      const d = input.units.find((x) => String(x.id) === String(u.id));
      return d ? { ...u, special_requirement: d.special_requirement } : u;
    }),
  };
  mockImageBatchStore.draft = input;
  return { ok: true };
}

/** DEV ONLY MOCK —— 提交执行动作并记录一条历史批次。 */
export async function applyMockRun(input: ImageBatchRunInput): Promise<ImageBatchRunResult> {
  const targets = mockImageBatchStore.workspace.units.filter((u) =>
    input.mode === 'full' ? true : input.unit_ids.includes(u.id),
  );
  const pageLabels = targets.map((u) => `P${u.page_no}`);
  const summary = targets.length ? `${pageLabels.join('、')}（${targets.length} 页）` : '未选择页面';
  const id = `batch-${mockImageBatchStore.history.length + 3}`;
  const item: BatchHistoryItem = {
    id,
    created_at: new Date('2026-10-13T09:00:00+08:00').toISOString(),
    page_labels: pageLabels,
    status: '待制作',
    instruction_summary: `${input.mode === 'full' ? '整批生成' : input.mode === 'trial' ? '先做试样' : '选择部分页面生成'}：${summary}`,
  };
  mockImageBatchStore.history = [item, ...mockImageBatchStore.history];
  return {
    batch_id: id,
    mode: input.mode,
    unit_ids: targets.map((u) => u.id),
    status: 'pending',
  };
}

/** DEV ONLY MOCK —— 复制整批生图任务（仅记录事件，不开发真实 AI 调用）。 */
export async function applyMockCopyTask(): Promise<{ task: { copied: boolean } }> {
  return { task: { copied: true } };
}

/** DEV ONLY MOCK —— 记录来源变化决策（keep / regenerate），更新工作台后由前端重新 GET。 */
export async function applyMockResolveSourceChange(
  unitId: number | string,
  action: SourceChangeAction,
): Promise<{ ok: boolean }> {
  mockImageBatchStore.workspace = {
    ...mockImageBatchStore.workspace,
    units: mockImageBatchStore.workspace.units.map((u) => {
      if (String(u.id) !== String(unitId)) return u;
      if (action === 'keep') return { ...u, source_changed: false, source_change_summary: null };
      return { ...u, source_changed: false, source_change_summary: null, status: 'needs_revision' };
    }),
  };
  return { ok: true };
}
