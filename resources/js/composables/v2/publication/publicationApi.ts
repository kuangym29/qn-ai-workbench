import { http } from '../../../api/http';
import type { ApiResponse } from '../../../api/types';

// Publication API adapter for the V2 制作→审核→发布 "发布" closure (P5-I01).
//
// Mirrors the review-submission adapter: the client NEVER decides whether a version is
// publishable — `state()` returns the authoritative `can_publish` / `reason` computed by
// the server (approved exact version + not yet published). The POST body is empty; every
// ownership / identity / approval field is server-derived.

// Only the three content targets are publishable. `image_batch` is intentionally excluded:
// the backend 422s on it and the ImageBatch editor does NOT mount this component.
export type PublicationTarget = 'image_post_copy' | 'article_copy' | 'video_production';

export interface PublicationScope {
  projectId: number | string;
  itemId: number | string;
  branchId: number | string;
  target: PublicationTarget;
}

// Live state returned by GET. Every field is computed server-side; the UI renders it
// verbatim and never re-derives publishability or identity.
export interface PublicationState {
  target_type: PublicationTarget;
  current_version_label: string | null;
  approved: boolean;
  review_request_id: number | null;
  published: boolean;
  publication_id: number | null;
  published_at: string | null;
  can_publish: boolean;
  reason: string | null;
}

export interface PublicationResult {
  created: boolean;
  publication: { id: number };
}

function base(project: number | string, item: number | string, branch: number | string, target: PublicationTarget): string {
  return `/api/projects/${Number(project)}/items/${Number(item)}/branches/${Number(branch)}/publication/${target}`;
}

export const publicationApi = {
  async load(
    project: number | string,
    item: number | string,
    branch: number | string,
    target: PublicationTarget,
  ): Promise<PublicationState> {
    return (await http.get<ApiResponse<PublicationState>>(base(project, item, branch, target))).data.data;
  },
  async publish(
    project: number | string,
    item: number | string,
    branch: number | string,
    target: PublicationTarget,
  ): Promise<PublicationResult> {
    return (await http.post<ApiResponse<PublicationResult>>(base(project, item, branch, target), {})).data.data;
  },
};
