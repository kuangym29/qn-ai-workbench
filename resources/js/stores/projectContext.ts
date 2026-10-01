// Project context: the "current project" chosen by the user.
//
// DEV-W02 rule — SERVER IS THE SOURCE OF TRUTH:
//   * The server-side ProjectContext (session) holds the current project.
//   * The UI only mirrors/caches what the server says.
//   * Selecting a project MUST call POST /api/projects/{id}/select first; only after a
//     successful server response do we update the local mirror.
//   * On every Inertia render we hydrate from the shared `currentProject` prop, so a
//     page refresh always reflects the server's current project.
//   * localStorage is NO LONGER an authority and is intentionally unused here.
import { reactive } from 'vue';
import type { Project } from '../api/types';
import { projectsApi } from '../api/projects';

interface ProjectContextState {
  current: Project | null;
}

export const projectContext = reactive<ProjectContextState>({
  current: null,
});

// Hydrate from the server-provided Inertia shared prop. Call this on mount and whenever
// the Inertia page props change (see AdminLayout), so the local mirror always trails
// the server session.
export function hydrateFromServer(project: Project | null): void {
  projectContext.current = project;
}

// Switch the current project. Goes through the server first; updates the local mirror
// only after the server confirms. Returns the selected project.
export async function selectProject(id: number | string): Promise<Project> {
  const project = await projectsApi.select(id);
  projectContext.current = project;
  return project;
}

// Mirror-only setter, used by mock mode where there is no select round-trip.
export function setCurrentProject(project: Project | null): void {
  projectContext.current = project;
}
