// V2 视频号视频制作 —— 数据合同类型。
//
// 产品原则：视频页面不复制图片文章已存在的信息。
// 视频制作只回答三个问题：
//   1. 整条视频整体怎么做？（batch_instruction，一段完整文本）
//   2. 旁白说什么？（narration，一份完整正式旁白）
//   3. 每张图具体怎么动、动多久、有什么特殊注意？（units）
// 系统/继承信息（序号 / 来源页）弱展示；时间轴由系统自动计算，日常页面不展示。
//
// 视频号单独选择（方案 A）：不额外出现「基础图片批次」渠道；
// 视频制作内部未来包含「准备/生成基础图片 → 视频化」，UI 仅轻量预留状态。
//
// R1 合同收口（V2-P3-C03-R1）：
//   - 正式 API 为 branch-aware：
//       GET/PUT /api/projects/{project}/items/{item}/branches/{branch}/video
//       POST    /api/projects/{project}/items/{item}/branches/{branch}/video/source-change
//     正式页面必须接收 projectId / itemId / branchId 三参数。
//   - VideoSaveInput units 以 source_page_id 定位（不提交前端行内 id）；
//     不提交 title / source_label / order / source_changed / source_change_summary /
//     timeline / total_duration（系统 / 继承字段，前端只读）。
//   - 来源变化「继续沿用」：POST source-change {action:'keep'} 成功后重新 GET；
//     禁止前端本地直接清掉 source_changed 假装保存成功。

export interface VideoProduction {
  id: number;
  project_id: number;
  content_item_id: number;
  /** 内容名称（来自 Content Item，不做成「视频名称」新字段） */
  title: string;
  /** 来源展示：栏目 / 来源标题 */
  source_label: string | null;
  /** 整批视频制作说明（一段完整文本，不拆字段） */
  batch_instruction: string;
  /** 完整旁白稿（一份完整正式旁白，不要求逐片段手工拆） */
  narration: string;
  /** 视频单元（每个来源图片 / 页面对应一个） */
  units: VideoUnit[];
  /** 来源内容后来是否有修改（系统检测，前端只读，不本地伪造） */
  source_changed: boolean;
  /** 来源修改提示摘要（不显示 Content Revision V3 等技术术语） */
  source_change_summary: string | null;
}

export interface VideoUnit {
  /** 前端行内定位用 id（不提交保存） */
  id: number;
  /** 片段顺序（来源页顺序，系统 / 继承，只读，不提交） */
  order: number;
  /** 来源页 id（保存时以此定位单元） */
  source_page_id: number | null;
  /** 来源页弱展示标签，如「封面」「P2」（只读，不提交） */
  source_label: string | null;
  /** 动态说明：这张图怎么动 */
  dynamic_instruction: string;
  /** 时长（秒） */
  duration_seconds: number;
  /** 备注（可选） */
  note: string | null;
}

// ---------------------------------------------------------------------------
// 系统计算（只读）
// ---------------------------------------------------------------------------

export interface VideoTimelineItem {
  order: number;
  source_label: string | null;
  /** 片段起止时间（秒，累积计算） */
  start_seconds: number;
  end_seconds: number;
  /** 旁白对应关系（系统未来自动分配；当前展示占位说明） */
  narration_note: string;
}

export interface VideoEditorWorkspace {
  video: VideoProduction;
  /** 系统计算：预计总时长（秒，只读，不提交） */
  total_duration: number;
  /** 系统计算：只读时间轴（只读，不提交） */
  timeline: VideoTimelineItem[];
}

/**
 * 保存输入（R1 正式白名单）：仅用户可编辑字段。
 * units 以 source_page_id 定位；不提交 id / order / source_label / title /
 * source_changed / source_change_summary / total_duration / timeline。
 */
export interface VideoSaveInput {
  batch_instruction: string;
  narration: string;
  units: {
    source_page_id: number | null;
    dynamic_instruction: string;
    duration_seconds: number;
    note: string | null;
  }[];
}

/** 来源变化决策：R1 仅「继续沿用」（keep）。 */
export type VideoSourceChangeAction = 'keep';

/** 来源变化决策输入。 */
export interface VideoSourceChangeInput {
  action: VideoSourceChangeAction;
}
