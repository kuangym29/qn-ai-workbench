// Shared domain types for QN AI 内容工作台 Lite V1.0 (DEV-W01 scope).
// Only Project and Column are in scope here; Topic / Content Item / Production Task /
// Channel Task belong to later tasks and are NOT modeled in this layer.

export interface Project {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  created_at: string;
  updated_at: string;
}

export interface Column {
  id: string;
  project_id: string;
  name: string;
  slug: string;
  description: string | null;
  sort_order: number;
  created_at: string;
  updated_at: string;
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
