// V2 视频号视频制作 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责：系统计算只读信息
//   1. computeTotalDuration()    预计总时长（秒）
//   2. formatDuration()          秒 → 展示文本（如 24s）
//   3. buildTimeline()           只读时间轴（片段 / 起止时间 / 旁白对应关系）
//   4. sortUnitsByOrder()        单元按 order 稳定排序
//   5. buildSaveInput()          用户可编辑字段收敛（系统字段不提交）
import type {
  VideoProduction,
  VideoSaveInput,
  VideoTimelineItem,
  VideoUnit,
} from '../../../types/v2/video/video';

/** 预计总时长：所有单元时长之和（无效值为 0）。 */
export function computeTotalDuration(units: VideoUnit[]): number {
  let total = 0;
  for (const u of units) {
    const n = Number(u.duration_seconds);
    if (Number.isFinite(n) && n > 0) total += n;
  }
  return total;
}

/** 秒 → 展示文本（V1 单位秒；60s+ 也按秒展示，避免过度设计）。 */
export function formatDuration(seconds: number): string {
  const n = Math.max(0, Math.round(seconds * 100) / 100);
  return `${n}s`;
}

/** 只读时间轴：按 order 排序后累积起止；旁白对应关系由系统未来自动分配。 */
export function buildTimeline(units: VideoUnit[]): VideoTimelineItem[] {
  const sorted = sortUnitsByOrder(units);
  let cursor = 0;
  const timeline: VideoTimelineItem[] = [];
  for (const u of sorted) {
    const dur = Number(u.duration_seconds);
    const d = Number.isFinite(dur) && dur > 0 ? dur : 0;
    timeline.push({
      order: u.order,
      source_page_id: u.source_page_id,
      source_label: u.source_label,
      duration_seconds: d,
      start_seconds: cursor,
      end_seconds: cursor + d,
    });
    cursor += d;
  }
  return timeline;
}

/** 单元按 order 稳定排序（无 order 的排最后，保持原相对顺序）。 */
export function sortUnitsByOrder(units: VideoUnit[]): VideoUnit[] {
  return [...units].sort((a, b) => {
    const ao = Number(a.order);
    const bo = Number(b.order);
    const av = Number.isFinite(ao) ? ao : Number.MAX_SAFE_INTEGER;
    const bv = Number.isFinite(bo) ? bo : Number.MAX_SAFE_INTEGER;
    return av - bv;
  });
}

/**
 * 收敛为用户可编辑的保存输入（R1 白名单）：
 * units 以 source_page_id 定位（不提交前端行内 id）；
 * 不提交 order / source_label / title / source_changed / total_duration / timeline。
 */
export function buildSaveInput(video: VideoProduction): VideoSaveInput {
  return {
    batch_instruction: video.batch_instruction,
    narration: video.narration,
    units: sortUnitsByOrder(video.units).map((u) => ({
      source_page_id: u.source_page_id,
      dynamic_instruction: u.dynamic_instruction,
      duration_seconds: u.duration_seconds,
      note: u.note,
    })),
  };
}

/** 时长输入：有限非负秒数；非法输入 → 保持原值。 */
export function normalizeDurationSeconds(v: unknown, fallback: number): number {
  const n = Number(v);
  return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) / 100 : fallback;
}
