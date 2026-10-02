// Public SourceReference API — DEV-W07.
//
// Two separate scopes, each managing only its own roles:
//   Project level → GET/POST /api/projects/{project}/sources[/{ref}]
//   Item level    → GET/POST /api/.../items/{item}/sources[/{ref}]
//
// Ownership (project_id / content_item_id) comes from the URL and `authority` is derived
// server-side from the role, so the payload only ever carries role / source_path / note.
//
// This adapter manages *path references* only: it never uploads, opens, syncs or
// checks whether a file exists on disk. There is deliberately no mock layer and no
// delete method — provenance records are not deletable through the app.
import type { ApiResponse, SourceReference, SourceReferenceInput } from './types';
import { http } from './http';

type ProjectScope = { projectId: number | string };

type ItemScope = {
  projectId: number | string;
  columnId: number | string;
  topicId: number | string;
  itemId: number | string;
};

function projectPrefix({ projectId }: ProjectScope): string {
  return `/api/projects/${Number(projectId)}/sources`;
}

function itemPrefix({ projectId, columnId, topicId, itemId }: ItemScope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}/sources`;
}

export const projectSourcesApi = {
  list(scope: ProjectScope): Promise<SourceReference[]> {
    return http
      .get<ApiResponse<SourceReference[]>>(projectPrefix(scope))
      .then((r) => r.data.data);
  },

  get(scope: ProjectScope, id: number): Promise<SourceReference> {
    return http
      .get<ApiResponse<SourceReference>>(`${projectPrefix(scope)}/${id}`)
      .then((r) => r.data.data);
  },

  create(scope: ProjectScope, input: SourceReferenceInput): Promise<SourceReference> {
    return http
      .post<ApiResponse<SourceReference>>(projectPrefix(scope), input)
      .then((r) => r.data.data);
  },

  update(
    scope: ProjectScope,
    id: number,
    input: Partial<SourceReferenceInput>,
  ): Promise<SourceReference> {
    return http
      .patch<ApiResponse<SourceReference>>(`${projectPrefix(scope)}/${id}`, input)
      .then((r) => r.data.data);
  },
};

export const itemSourcesApi = {
  list(scope: ItemScope): Promise<SourceReference[]> {
    return http
      .get<ApiResponse<SourceReference[]>>(itemPrefix(scope))
      .then((r) => r.data.data);
  },

  get(scope: ItemScope, id: number): Promise<SourceReference> {
    return http
      .get<ApiResponse<SourceReference>>(`${itemPrefix(scope)}/${id}`)
      .then((r) => r.data.data);
  },

  create(scope: ItemScope, input: SourceReferenceInput): Promise<SourceReference> {
    return http
      .post<ApiResponse<SourceReference>>(itemPrefix(scope), input)
      .then((r) => r.data.data);
  },

  update(
    scope: ItemScope,
    id: number,
    input: Partial<SourceReferenceInput>,
  ): Promise<SourceReference> {
    return http
      .patch<ApiResponse<SourceReference>>(`${itemPrefix(scope)}/${id}`, input)
      .then((r) => r.data.data);
  },
};
