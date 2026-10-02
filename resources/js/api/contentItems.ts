// Public ContentItem API — always scoped to Project → Column → Topic.
//
// Real endpoints (DEV-005; every response wrapped as {"data": ...}):
//   GET    /api/projects/{p}/columns/{c}/topics/{t}/items          -> ContentItem[]
//   POST   /api/projects/{p}/columns/{c}/topics/{t}/items          -> ContentItem
//   GET    /api/projects/{p}/columns/{c}/topics/{t}/items/{item}   -> ContentItem
//   PATCH  /api/projects/{p}/columns/{c}/topics/{t}/items/{item}   -> ContentItem
//
// The real API is the default and the only source of data here: there is deliberately NO
// mock layer for Topic/ContentItem, so the UI can never display invented records.
import type { ApiResponse, ContentItem, ContentItemInput } from './types';
import { http } from './http';

export const contentItemsApi = {
  list(
    projectId: number | string,
    columnId: number | string,
    topicId: number | string,
  ): Promise<ContentItem[]> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    const tid = Number(topicId);
    return http
      .get<ApiResponse<ContentItem[]>>(
        `/api/projects/${pid}/columns/${cid}/topics/${tid}/items`,
      )
      .then((r) => r.data.data);
  },

  get(
    projectId: number | string,
    columnId: number | string,
    topicId: number | string,
    id: number | string,
  ): Promise<ContentItem | null> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    const tid = Number(topicId);
    const iid = Number(id);
    return http
      .get<ApiResponse<ContentItem>>(
        `/api/projects/${pid}/columns/${cid}/topics/${tid}/items/${iid}`,
      )
      .then((r) => r.data.data);
  },

  create(
    projectId: number | string,
    columnId: number | string,
    topicId: number | string,
    input: ContentItemInput,
  ): Promise<ContentItem> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    const tid = Number(topicId);
    return http
      .post<ApiResponse<ContentItem>>(
        `/api/projects/${pid}/columns/${cid}/topics/${tid}/items`,
        input,
      )
      .then((r) => r.data.data);
  },

  update(
    projectId: number | string,
    columnId: number | string,
    topicId: number | string,
    id: number | string,
    input: Partial<ContentItemInput>,
  ): Promise<ContentItem | null> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    const tid = Number(topicId);
    const iid = Number(id);
    return http
      .patch<ApiResponse<ContentItem>>(
        `/api/projects/${pid}/columns/${cid}/topics/${tid}/items/${iid}`,
        input,
      )
      .then((r) => r.data.data);
  },
};
