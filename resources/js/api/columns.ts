// Public Column API. Always project-scoped (Project = highest isolation boundary).
// Real endpoints (DEV-002 backend):
//   GET    /api/projects/{project}/columns
//   GET    /api/projects/{project}/columns/{column}
//   POST   /api/projects/{project}/columns
//   PUT    /api/projects/{project}/columns/{column}
import axios from 'axios';
import type { Column, ColumnInput } from './types';
import { USE_MOCK } from './config';
import { mockColumns } from './mock/columns';

export const columnsApi = {
  list(projectId: string): Promise<Column[]> {
    if (USE_MOCK) return mockColumns.list(projectId);
    return axios
      .get<Column[]>(`/api/projects/${projectId}/columns`)
      .then((r) => r.data);
  },

  get(projectId: string, id: string): Promise<Column | undefined> {
    if (USE_MOCK) return mockColumns.get(projectId, id);
    return axios
      .get<Column>(`/api/projects/${projectId}/columns/${id}`)
      .then((r) => r.data);
  },

  create(projectId: string, input: ColumnInput): Promise<Column> {
    if (USE_MOCK) return mockColumns.create(projectId, input);
    return axios
      .post<Column>(`/api/projects/${projectId}/columns`, input)
      .then((r) => r.data);
  },

  update(
    projectId: string,
    id: string,
    input: Partial<ColumnInput>,
  ): Promise<Column | undefined> {
    if (USE_MOCK) return mockColumns.update(projectId, id, input);
    return axios
      .put<Column>(`/api/projects/${projectId}/columns/${id}`, input)
      .then((r) => r.data);
  },
};
