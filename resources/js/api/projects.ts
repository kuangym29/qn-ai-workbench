// Public Project API — the ONLY call surface the UI imports.
// When USE_MOCK is true -> mock layer. Otherwise -> real DEV-003 REST endpoints.
//
// Real endpoints (DEV-003; every response wrapped as {"data": ...}):
//   GET    /api/projects                       -> Project[]
//   POST   /api/projects                       -> Project
//   GET    /api/projects/current               -> Project | null
//   POST   /api/projects/{project}/select      -> Project
//   PATCH  /api/projects/{project}             -> Project
//
// There is intentionally NO GET /api/projects/{project}: editing a project reads it from
// the already-loaded list (see pages/Projects/Form.vue), so we never add a backend show.
import type { ApiResponse, Project, ProjectInput } from './types';
import { USE_MOCK } from './config';
import { http } from './http';
import { mockProjects } from './mock/projects';

export const projectsApi = {
  list(): Promise<Project[]> {
    if (USE_MOCK) return mockProjects.list();
    return http.get<ApiResponse<Project[]>>('/api/projects').then((r) => r.data.data);
  },

  current(): Promise<Project | null> {
    if (USE_MOCK) return mockProjects.current();
    return http.get<ApiResponse<Project | null>>('/api/projects/current').then((r) => r.data.data);
  },

  // Selecting a project is a server-side session action (DEV-003 ProjectContext).
  select(id: number | string): Promise<Project> {
    if (USE_MOCK) return mockProjects.select(id);
    const pid = Number(id);
    return http
      .post<ApiResponse<Project>>(`/api/projects/${pid}/select`)
      .then((r) => r.data.data);
  },

  create(input: ProjectInput): Promise<Project> {
    if (USE_MOCK) return mockProjects.create(input);
    return http.post<ApiResponse<Project>>('/api/projects', input).then((r) => r.data.data);
  },

  update(id: number | string, input: Partial<ProjectInput>): Promise<Project | null> {
    if (USE_MOCK) return mockProjects.update(id, input);
    const pid = Number(id);
    return http.patch<ApiResponse<Project>>(`/api/projects/${pid}`, input).then((r) => r.data.data);
  },
};
