// Mock implementation for Project operations. Simulates network latency and persists
// to localStorage via mockDb. Replace by flipping USE_MOCK (see api/config.ts).
import type { Project, ProjectInput } from '../types';
import { mockDb } from './db';

function delay<T>(value: T, ms = 250): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

function genId(): string {
  return 'p_' + Math.random().toString(36).slice(2, 10);
}

export const mockProjects = {
  list(): Promise<Project[]> {
    return delay([...mockDb.getProjects()]);
  },

  get(id: string): Promise<Project | undefined> {
    return delay(mockDb.getProjects().find((p) => p.id === id));
  },

  create(input: ProjectInput): Promise<Project> {
    const projects = mockDb.getProjects();
    const project: Project = {
      id: genId(),
      name: input.name,
      slug: input.slug,
      description: input.description ?? null,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    };
    mockDb.setProjects([...projects, project]);
    return delay(project);
  },

  update(id: string, input: Partial<ProjectInput>): Promise<Project | undefined> {
    const projects = mockDb.getProjects();
    const idx = projects.findIndex((p) => p.id === id);
    if (idx === -1) return delay(undefined);
    const updated: Project = {
      ...projects[idx],
      ...input,
      description: input.description !== undefined ? input.description : projects[idx].description,
      updated_at: new Date().toISOString(),
    };
    projects[idx] = updated;
    mockDb.setProjects(projects);
    return delay(updated);
  },
};
