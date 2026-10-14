// V2「待我审核」轻量审核中心 —— 生产 API adapter（独立，不引用任何 Mock）。
//
// 端点约定（V2 foundation 风格；正式端点由 WorkBuddy Phase 2 / Work 主程序集成对齐，
// 路径不同仅需替换 base）：
//   GET  /api/projects/{project}/reviews?status=pending  → {"data": ReviewItem[]}
//   GET  /api/projects/{project}/reviews/{review}        → {"data": ReviewDetail}
//   POST /api/projects/{project}/reviews/{review}/decision → body ReviewDecisionInput → {"data": ReviewDecisionResult}
//
// 正式链路：前端提交决策 → 后端 append-only 记录 → 前端重新拉取列表。
// 前端不伪造服务端状态、不本地算相似度、不本地判定审核结果。
import { http } from '../../../api/http';
import type { ApiResponse } from '../../../api/types';
import type {
  ReviewDecisionInput,
  ReviewDecisionResult,
  ReviewDetail,
  ReviewItem,
} from '../../../types/v2/review/review';

function base(projectId: number | string, reviewId?: number | string): string {
  const p = `/api/projects/${Number(projectId)}/reviews`;
  return reviewId == null ? p : `${p}/${Number(reviewId)}`;
}

export const reviewApi = {
  /** 拉取待审核列表（默认只显示 pending）。 */
  async list(projectId: number | string): Promise<ReviewItem[]> {
    const res = await http.get<ApiResponse<ReviewItem[]>>(`${base(projectId)}?status=pending`);
    return res.data.data;
  },

  /** 拉取单条审核详情。 */
  async detail(projectId: number | string, reviewId: number | string): Promise<ReviewDetail> {
    const res = await http.get<ApiResponse<ReviewDetail>>(base(projectId, reviewId));
    return res.data.data;
  },

  /** 提交审核决策（通过 / 退回修改 + 批注）。 */
  async decide(
    projectId: number | string,
    reviewId: number | string,
    input: ReviewDecisionInput,
  ): Promise<ReviewDecisionResult> {
    const res = await http.post<ApiResponse<ReviewDecisionResult>>(
      `${base(projectId, reviewId)}/decision`,
      input,
    );
    return res.data.data;
  },
};
