// Mock vs real API switch.
//
// DEV-W01 is frontend-only. The backend CRUD for Project/Column belongs to DEV-002
// (per docs/DEV_TASKS.md), so we default to Mock ON. Mock and real call layers are
// fully separated: flip VITE_USE_MOCK=false to route every call to the real REST
// endpoints documented in api/projects.ts and api/columns.ts — no other code changes.
export const USE_MOCK: boolean =
  (import.meta.env.VITE_USE_MOCK ?? 'true') !== 'false';
