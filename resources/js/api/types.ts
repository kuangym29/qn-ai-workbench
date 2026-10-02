// Shared domain types for QN AI 内容工作台 Lite V1.0 (DEV-W01 / DEV-W02 / DEV-W03 scope).
// Project, ContentColumn, Topic and ContentItem are modeled here. Production Task /
// Channel Task / ContentPage belong to later tasks and are NOT modeled in this layer.

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
