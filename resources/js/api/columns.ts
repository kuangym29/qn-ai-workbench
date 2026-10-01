// Public Column API — always project-scoped (Project is the highest isolation boundary).
//
// Real endpoints (DEV-003; every response wrapped as {"data": ...}):
//   GET    /api/projects/{project}/columns            -> ContentColumn[]
//   POST   /api/projects/{project}/columns            -> ContentColumn
//   GET    /api/projects/{project}/columns/{column}   -> ContentColumn
//   PATCH  /api/projects/{project}/columns/{column}   -> ContentColumn
import type { ApiResponse, Column, ColumnInput } from './types';
import { USE_MOCK } from './config';
import { http } from './http';
import { mockColumns } from './mock/columns';

export const columnsApi = {
  list(projectId: number | string): Promise<Column[]> {
    if (USE_MOCK) return mockColumns.list(projectId);
    const pid = Number(projectId);
    return http.get<ApiResponse<Column[]>>(`/api/projects/${pid}/columns`).then((r) => r.data.data);
  },

  get(projectId: number | string, id: number | string): Promise<Column | null> {
    if (USE_MOCK) return mockColumns.get(projectId, id);
    const pid = Number(projectId);
    const cid = Number(id);
    return http
      .get<ApiResponse<Column>>(`/api/projects/${pid}/columns/${cid}`)
      .then((r) => r.data.data);
  },

  create(projectId: number | string, input: ColumnInput): Promise<Column> {
    if (USE_MOCK) return mockColumns.create(projectId, input);
    const pid = Number(projectId);
    return http
      .post<ApiResponse<Column>>(`/api/projects/${pid}/columns`, input)
      .then((r) => r.data.data);
  },

  update(
    projectId: number | string,
    id: number | string,
    input: Partial<ColumnInput>,
  ): Promise<Column | null> {
    if (USE_MOCK) return mockColumns.update(projectId, id, input);
    const pid = Number(projectId);
    const cid = Number(id);
    return http
      .patch<ApiResponse<Column>>(`/api/projects/${pid}/columns/${cid}`, input)
      .then((r) => r.data.data);
  },
};
