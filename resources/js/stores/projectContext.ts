// Project context: the "current project" chosen by the user. Enforces the
// "项目上下文优先" rule from the task brief — Columns and downstream entities are
// always accessed through the currently selected Project. Persisted to localStorage.
import { reactive } from 'vue';
import type { Project } from '../api/types';

const STORAGE_KEY = 'devw01_v1_current_project';

interface ProjectContextState {
  current: Project | null;
}

function load(): Project | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as Project) : null;
  } catch {
    return null;
  }
}

export const projectContext = reactive<ProjectContextState>({
  current: load(),
});

export function setCurrentProject(p: Project | null): void {
  projectContext.current = p;
  if (p) localStorage.setItem(STORAGE_KEY, JSON.stringify(p));
  else localStorage.removeItem(STORAGE_KEY);
}

// If the stored current project no longer exists in the live list, drop it so the
// UI falls back to the "select a project" state instead of showing a phantom project.
export function reconcileCurrentProject(projects: Project[]): void {
  if (!projectContext.current) return;
  const exists = projects.some((p) => p.id === projectContext.current!.id);
  if (!exists) projectContext.current = null;
}
