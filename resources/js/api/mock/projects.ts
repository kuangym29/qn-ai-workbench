// Mock implementation for Project operations. Simulates network latency and persists to
// localStorage via mockDb. Mirrors the real DEV-003 surface (list / current / select /
// create / update) so the UI behaves identically in standalone mock mode.
import type { Project, ProjectInput } from '../types';
import { mockDb } from './db';

function delay<T>(value: T, ms = 250): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

let seq = 100;
function genId(): number {
  return ++seq;
}

export const mockProjects = {
  list(): Promise<Project[]> {
    return delay([...mockDb.getProjects()]);
  },

  get(id: number | string): Promise<Project | null> {
    return delay(mockDb.getProjects().find((p) => p.id === Number(id)) ?? null);
  },

  current(): Promise<Project | null> {
    const id = mockDb.getCurrentProjectId();
    if (id === null) return delay(null);
    return delay(mockDb.getProjects().find((p) => p.id === id) ?? null);
  },

  // Mock of POST /api/projects/{id}/select: records the current project locally.
  select(id: number | string): Promise<Project> {
    const pid = Number(id);
    const project = mockDb.getProjects().find((p) => p.id === pid);
    if (!project) return Promise.reject(new Error('项目不存在'));
    mockDb.setCurrentProjectId(pid);
    return delay(project);
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

  update(id: number | string, input: Partial<ProjectInput>): Promise<Project | null> {
    const projects = mockDb.getProjects();
    const idx = projects.findIndex((p) => p.id === Number(id));
    if (idx === -1) return delay(null);
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
