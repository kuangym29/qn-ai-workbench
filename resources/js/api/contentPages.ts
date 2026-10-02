// Public ContentPage / CopyRevision API — always scoped to
// Project → Column → Topic → ContentItem.
//
// Real endpoints (DEV-007A; every response wrapped as {"data": ...}):
//   GET    /api/.../items/{item}/pages                  -> ContentPage[]
//   POST   /api/.../items/{item}/pages                  -> ContentPage          (201)
//   GET    /api/.../items/{item}/pages/{page}           -> ContentPage
//   PATCH  /api/.../items/{item}/pages/{page}           -> ContentPage          (page_type only)
//   POST   /api/.../items/{item}/pages/{page}/drafts    -> ContentPageVersion   (201)
//   POST   /api/.../items/{item}/pages/reorder          -> ContentPage[]        (full reorder)
//   POST   /api/.../items/{item}/copy/confirm           -> ContentCopyRevision  (201)
//   GET    /api/.../items/{item}/copy/revisions         -> ContentCopyRevision[] (revision_no DESC)
//   GET    /api/.../items/{item}/copy/revisions/{rev}   -> ContentCopyRevision  (page_versions present)
//   GET    /api/.../items/{item}/copy/current           -> ContentCopyRevision | null
//   GET    /api/.../items/{item}/copy/working           -> ContentPage[]         (page_no ASC)
//
// The real API is the only source of data here: there is deliberately NO mock layer for
// ContentPage, so the UI can never display invented records. Every response is unwrapped
// from the {"data": ...} envelope in one place.
import type {
  ApiResponse,
  ContentCopyRevision,
  ContentPage,
  ContentPageVersion,
  PageCopyFields,
  PageType,
} from './types';
import { http } from './http';

type Scope = {
  projectId: number | string;
  columnId: number | string;
  topicId: number | string;
  itemId: number | string;
};

// Build the shared item prefix. Numbers are normalized so string props from Inertia still
// produce well-formed URLs.
function prefix({ projectId, columnId, topicId, itemId }: Scope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}`;
}

export const contentPagesApi = {
  listPages(scope: Scope): Promise<ContentPage[]> {
    return http
      .get<ApiResponse<ContentPage[]>>(`${prefix(scope)}/pages`)
      .then((r) => r.data.data);
  },

  getPage(scope: Scope, pageId: number | string): Promise<ContentPage> {
    return http
      .get<ApiResponse<ContentPage>>(`${prefix(scope)}/pages/${Number(pageId)}`)
      .then((r) => r.data.data);
  },

  createPage(
    scope: Scope,
    input: { page_no: number; page_type: PageType },
  ): Promise<ContentPage> {
    return http
      .post<ApiResponse<ContentPage>>(`${prefix(scope)}/pages`, input)
      .then((r) => r.data.data);
  },

  // PATCH a page — only ever sends `page_type`; page_no is server-managed and forbidden.
  updatePageType(scope: Scope, pageId: number | string, pageType: PageType): Promise<ContentPage> {
    return http
      .patch<ApiResponse<ContentPage>>(`${prefix(scope)}/pages/${Number(pageId)}`, {
        page_type: pageType,
      })
      .then((r) => r.data.data);
  },

  // Append a draft version. Unknown fields are rejected with 422, so only the seven legal
  // copy / note fields are ever sent (the caller already narrowed them by page type).
  appendDraft(
    scope: Scope,
    pageId: number | string,
    fields: PageCopyFields,
  ): Promise<ContentPageVersion> {
    return http
      .post<ApiResponse<ContentPageVersion>>(
        `${prefix(scope)}/pages/${Number(pageId)}/drafts`,
        fields,
      )
      .then((r) => r.data.data);
  },

  // Full reorder: the array must contain every page id of the item, in the new order.
  reorderPages(scope: Scope, pageIds: number[]): Promise<ContentPage[]> {
    return http
      .post<ApiResponse<ContentPage[]>>(`${prefix(scope)}/pages/reorder`, {
        page_ids: pageIds,
      })
      .then((r) => r.data.data);
  },

  // Atomic formal confirmation. No body; returns the freshly created revision snapshot.
  confirmCopy(scope: Scope): Promise<ContentCopyRevision> {
    return http
      .post<ApiResponse<ContentCopyRevision>>(`${prefix(scope)}/copy/confirm`)
      .then((r) => r.data.data);
  },

  listRevisions(scope: Scope): Promise<ContentCopyRevision[]> {
    return http
      .get<ApiResponse<ContentCopyRevision[]>>(`${prefix(scope)}/copy/revisions`)
      .then((r) => r.data.data);
  },

  getRevision(scope: Scope, revisionId: number | string): Promise<ContentCopyRevision> {
    return http
      .get<ApiResponse<ContentCopyRevision>>(
        `${prefix(scope)}/copy/revisions/${Number(revisionId)}`,
      )
      .then((r) => r.data.data);
  },

  // Returns null when no formal revision exists yet ({"data": null}).
  getCurrentRevision(scope: Scope): Promise<ContentCopyRevision | null> {
    return http
      .get<ApiResponse<ContentCopyRevision | null>>(`${prefix(scope)}/copy/current`)
      .then((r) => r.data.data);
  },

  // Working copy: each page carries its max-version_no version (draft or formal snapshot).
  getWorkingCopy(scope: Scope): Promise<ContentPage[]> {
    return http
      .get<ApiResponse<ContentPage[]>>(`${prefix(scope)}/copy/working`)
      .then((r) => r.data.data);
  },
};
