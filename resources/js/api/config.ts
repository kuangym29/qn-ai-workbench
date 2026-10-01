// Mock vs real API switch.
//
// DEV-W02 integration: the real DEV-003 REST API is the DEFAULT. The Mock layer is kept
// only for standalone frontend development and must be enabled explicitly via
// VITE_USE_MOCK=true. When mock is off (default), every call hits the real endpoints in
// api/projects.ts and api/columns.ts, which fully unwrap the {"data": ...} envelope.
const raw = import.meta.env.VITE_USE_MOCK;
export const USE_MOCK: boolean = raw === 'true';
