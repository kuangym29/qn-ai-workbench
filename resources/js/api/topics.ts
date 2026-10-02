// Public Topic API — always scoped to Project → Column.
//
// Real endpoints (DEV-005; every response wrapped as {"data": ...}):
//   GET    /api/projects/{project}/columns/{column}/topics          -> Topic[]
//   POST   /api/projects/{project}/columns/{column}/topics          -> Topic
//   GET    /api/projects/{project}/columns/{column}/topics/{topic}  -> Topic
//   PATCH  /api/projects/{project}/columns/{column}/topics/{topic}  -> Topic
//
// The real API is the default and the only source of data here: there is deliberately NO
// mock layer for Topic/ContentItem, so the UI can never display invented records.
import type { ApiResponse, Topic, TopicInput } from './types';
import { http } from './http';

export const topicsApi = {
  list(projectId: number | string, columnId: number | string): Promise<Topic[]> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    return http
      .get<ApiResponse<Topic[]>>(`/api/projects/${pid}/columns/${cid}/topics`)
      .then((r) => r.data.data);
  },

  get(
    projectId: number | string,
    columnId: number | string,
    id: number | string,
  ): Promise<Topic | null> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    const tid = Number(id);
    return http
      .get<ApiResponse<Topic>>(`/api/projects/${pid}/columns/${cid}/topics/${tid}`)
      .then((r) => r.data.data);
  },

  create(
    projectId: number | string,
    columnId: number | string,
    input: TopicInput,
  ): Promise<Topic> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    return http
      .post<ApiResponse<Topic>>(`/api/projects/${pid}/columns/${cid}/topics`, input)
      .then((r) => r.data.data);
  },

  update(
    projectId: number | string,
    columnId: number | string,
    id: number | string,
    input: Partial<TopicInput>,
  ): Promise<Topic | null> {
    const pid = Number(projectId);
    const cid = Number(columnId);
    const tid = Number(id);
    return http
      .patch<ApiResponse<Topic>>(`/api/projects/${pid}/columns/${cid}/topics/${tid}`, input)
      .then((r) => r.data.data);
  },
};
