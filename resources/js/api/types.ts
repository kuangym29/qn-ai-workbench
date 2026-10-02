// Shared domain types for QN AI 内容工作台 Lite V1.0.
// Project, ContentColumn, Topic, ContentItem (DEV-W01..DEV-W03) and ContentPage /
// ContentCopyRevision (DEV-007A, wired in DEV-W04) are modeled here. Production Task /
// Channel Task belong to later tasks and are NOT modeled in this layer.

// DEV-003 / DEV-005 wrap every response as {"data": ...}. Mirror that envelope here so the
// API adapter can unpack it in one place and never leak the wrapper into the UI.
export interface ApiResponse<T> {
  data: T;
}

export interface Project {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Column {
  id: number;
  project_id: number;
  name: string;
  slug: string;
  description: string | null;
  sort_order: number;
  created_at: string | null;
  updated_at: string | null;
}

// Topic (DEV-005). Always scoped to Project → Column. The server owns project_id and
// content_column_id (they are "prohibited" on write), so they are read-only here.
export interface Topic {
  id: number;
  project_id: number;
  content_column_id: number;
  title: string;
  description: string | null;
  created_at: string | null;
  updated_at: string | null;
}

// DEV-004 status normalization: these four values are the ONLY legal copy statuses.
// Never widen this to an arbitrary string — the backend CopyStatus enum is the contract,
// so 'draft' / 'completed' / 'approved' must never appear anywhere in the UI.
export type CopyStatus = 'not_started' | 'editing' | 'pending_confirmation' | 'confirmed';

export const COPY_STATUSES: CopyStatus[] = [
  'not_started',
  'editing',
  'pending_confirmation',
  'confirmed',
];

// Chinese display labels. The submitted/returned value always stays the English formal
// value (e.g. 'pending_confirmation'); only the label is localized.
export const COPY_STATUS_LABELS: Record<CopyStatus, string> = {
  not_started: '未开始',
  editing: '编辑中',
  pending_confirmation: '待确认',
  confirmed: '已确认',
};

// ContentItem (DEV-005). Always scoped to Project → Column → Topic. The server owns
// project_id / content_column_id / topic_id, so they are read-only here.
export interface ContentItem {
  id: number;
  project_id: number;
  content_column_id: number;
  topic_id: number;
  title: string;
  copy_status: CopyStatus;
  created_at: string | null;
  updated_at: string | null;
}

// DEV-007A — ContentPage / CopyRevision domain types.
//
// A ContentPage is a stable page identity inside a ContentItem. Its copy lives in versions;
// `latest_version` is the version with the max version_no (a draft or a formal snapshot).
export type PageType = 'cover' | 'content' | 'column_closing' | 'fixed_back_cover';

export const PAGE_TYPES: PageType[] = [
  'cover',
  'content',
  'column_closing',
  'fixed_back_cover',
];

export const PAGE_TYPE_LABELS: Record<PageType, string> = {
  cover: '封面',
  content: '正文',
  column_closing: '栏目收尾',
  fixed_back_cover: '固定封底',
};

// The seven copy / note fields a page version may carry. All optional on write; omitted
// fields inherit the previous version (DEV-007A appendDraft). Unknown fields are rejected
// with 422, so only these keys are ever submitted.
export interface PageCopyFields {
  column_label?: string | null;
  cover_title?: string | null;
  cover_subtitle?: string | null;
  page_title?: string | null;
  page_small_text?: string | null;
  closing_line?: string | null;
  note?: string | null;
}

// A single page version (draft or formal snapshot). For drafts, copy_revision_id and the
// two snapshot fields are null. For a formal revision's page_versions they are populated.
export interface ContentPageVersion extends PageCopyFields {
  id: number;
  project_id: number;
  content_item_id: number;
  content_page_id: number;
  version_no: number;
  copy_revision_id: number | null;
  page_no_snapshot: number | null;
  page_type_snapshot: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ContentPage {
  id: number;
  project_id: number;
  content_item_id: number;
  page_no: number;
  page_type: PageType;
  latest_version: ContentPageVersion | null;
  created_at: string | null;
  updated_at: string | null;
}

// A formal revision. `page_versions` is present on detail / current / confirm responses,
// but absent from the list response (revision_no DESC).
export interface ContentCopyRevision {
  id: number;
  project_id: number;
  content_item_id: number;
  revision_no: number;
  confirmed_at: string | null;
  page_versions?: ContentPageVersion[];
}

// Inputs for create/update. `id`, ownership keys and timestamps are server/store managed.
export interface ProjectInput {
  name: string;
  slug: string;
  description?: string | null;
}

export interface ColumnInput {
  name: string;
  slug: string;
  description?: string | null;
  sort_order?: number;
}

export interface TopicInput {
  title: string;
  description?: string | null;
}

export interface ContentItemInput {
  title: string;
  // Omitted on create on purpose: the server defaults a new item to 'not_started'.
  copy_status?: CopyStatus;
}
