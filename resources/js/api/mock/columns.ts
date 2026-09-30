// Mock implementation for Column operations, always scoped to a Project (Project is the
// highest isolation boundary per docs/ARCHITECTURE.md). Persists via mockDb.
import type { Column, ColumnInput } from '../types';
import { mockDb } from './db';

function delay<T>(value: T, ms = 250): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

function genId(): string {
  return 'c_' + Math.random().toString(36).slice(2, 10);
}

export const mockColumns = {
  // Columns belonging to a project, ordered by sort_order then name.
  list(projectId: string): Promise<Column[]> {
    const items = mockDb
      .getColumns()
      .filter((c) => c.project_id === projectId)
      .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name));
    return delay([...items]);
  },

  get(projectId: string, id: string): Promise<Column | undefined> {
    return delay(
      mockDb.getColumns().find((c) => c.project_id === projectId && c.id === id),
    );
  },

  create(projectId: string, input: ColumnInput): Promise<Column> {
    const columns = mockDb.getColumns();
    const column: Column = {
      id: genId(),
      project_id: projectId,
      name: input.name,
      slug: input.slug,
      description: input.description ?? null,
      sort_order: input.sort_order ?? columns.filter((c) => c.project_id === projectId).length + 1,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    };
    mockDb.setColumns([...columns, column]);
    return delay(column);
  },

  update(
    projectId: string,
    id: string,
    input: Partial<ColumnInput>,
  ): Promise<Column | undefined> {
    const columns = mockDb.getColumns();
    const idx = columns.findIndex((c) => c.project_id === projectId && c.id === id);
    if (idx === -1) return delay(undefined);
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
