// Public Project API. The ONLY call surface the UI imports.
// When USE_MOCK is true -> mock layer. Otherwise -> real REST endpoints.
// Real endpoints (to be provided by DEV-002 backend, documented here for the swap):
//   GET    /api/projects
//   GET    /api/projects/{project}
//   POST   /api/projects
//   PUT    /api/projects/{project}
import axios from 'axios';
import type { Project, ProjectInput } from './types';
import { USE_MOCK } from './config';
import { mockProjects } from './mock/projects';

export const projectsApi = {
  list(): Promise<Project[]> {
    if (USE_MOCK) return mockProjects.list();
    return axios.get<Project[]>('/api/projects').then((r) => r.data);
  },

  get(id: string): Promise<Project | undefined> {
    if (USE_MOCK) return mockProjects.get(id);
    return axios.get<Project>(`/api/projects/${id}`).then((r) => r.data);
  },

  create(input: ProjectInput): Promise<Project> {
    if (USE_MOCK) return mockProjects.create(input);
    return axios.post<Project>('/api/projects', input).then((r) => r.data);
  },

  update(id: string, input: Partial<ProjectInput>): Promise<Project | undefined> {
    if (USE_MOCK) return mockProjects.update(id, input);
    return axios.put<Project>(`/api/projects/${id}`, input).then((r) => r.data);
  },
};
