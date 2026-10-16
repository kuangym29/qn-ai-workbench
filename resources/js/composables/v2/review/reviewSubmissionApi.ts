import { http } from '../../../api/http';
import type { ApiResponse } from '../../../api/types';
export type SubmissionTarget = 'image_post_copy' | 'article_copy' | 'video_production' | 'image_batch';
export interface SubmissionScope { projectId: number | string; itemId: number | string; branchId: number | string; target: SubmissionTarget }
export interface PendingReview { id: number; status: 'pending'; review_target_type: SubmissionTarget; review_target_label: string; version_label: string; submitted_at: string }
export interface SubmissionState { pending: boolean; review: PendingReview | null }
function base(project: number | string, item: number | string, branch: number | string, target: SubmissionTarget) {
 return `/api/projects/${Number(project)}/items/${Number(item)}/branches/${Number(branch)}/review-submissions/${target}`;
}
export const reviewSubmissionApi = {
 async load(project: number | string, item: number | string, branch: number | string, target: SubmissionTarget): Promise<SubmissionState> {
  return (await http.get<ApiResponse<SubmissionState>>(base(project,item,branch,target))).data.data;
 },
 async submit(project: number | string, item: number | string, branch: number | string, target: SubmissionTarget): Promise<{created: boolean; review: PendingReview}> {
  return (await http.post<ApiResponse<{created: boolean; review: PendingReview}>>(base(project,item,branch,target), {})).data.data;
 },
};
