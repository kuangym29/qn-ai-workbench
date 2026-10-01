// Mock implementation for Column operations, always scoped to a Project (Project is the
// highest isolation boundary per docs/ARCHITECTURE.md). Persists via mockDb.
import type { Column, ColumnInput } from '../types';
import { mockDb } from './db';

function delay<T>(value: T, ms = 250): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

let seq = 100;
function genId(): number {
  return ++seq;
}

export const mockColumns = {
  // Columns belonging to a project, ordered by sort_order then name.
  list(projectId: number | string): Promise<Column[]> {
    const pid = Number(projectId);
    const items = mockDb
      .getColumns()
      .filter((c) => c.project_id === pid)
      .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name));
    return delay([...items]);
  },

  get(projectId: number | string, id: number | string): Promise<Column | null> {
    const pid = Number(projectId);
    const cid = Number(id);
    return delay(
      mockDb.getColumns().find((c) => c.project_id === pid && c.id === cid) ?? null,
    );
  },

  create(projectId: number | string, input: ColumnInput): Promise<Column> {
    const pid = Number(projectId);
    const columns = mockDb.getColumns();
    const column: Column = {
      id: genId(),
      project_id: pid,
      name: input.name,
      slug: input.slug,
      description: input.description ?? null,
      sort_order: input.sort_order ?? columns.filter((c) => c.project_id === pid).length + 1,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    };
    mockDb.setColumns([...columns, column]);
    return delay(column);
  },

  update(
    projectId: number | string,
    id: number | string,
    input: Partial<ColumnInput>,
  ): Promise<Column | null> {
    const pid = Number(projectId);
    const cid = Number(id);
    const columns = mockDb.getColumns();
    const idx = columns.findIndex((c) => c.project_id === pid && c.id === cid);
    if (idx === -1) return delay(null);
    const updated: Column = {
      ...columns[idx],
      ...input,
      description: input.description !== undefined ? input.description : columns[idx].description,
      updated_at: new Date().toISOString(),
    };
    columns[idx] = updated;
    mockDb.setColumns(columns);
    return delay(updated);
  },
};
