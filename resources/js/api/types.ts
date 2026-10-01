// Shared domain types for QN AI 内容工作台 Lite V1.0 (DEV-W01 / DEV-W02 scope).
// Only Project and Column are in scope here; Topic / Content Item / Production Task /
// Channel Task belong to later tasks and are NOT modeled in this layer.

// DEV-003 wraps every response as {"data": ...}. Mirror that envelope here so the API
// adapter can unpack it in one place and never leak the wrapper into the UI.
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

// Inputs for create/update. `id` and timestamps are server/store managed.
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
