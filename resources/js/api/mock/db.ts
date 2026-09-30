// localStorage-backed mock persistence. Keeps created/edited records alive across
// page navigations within a session (and across reloads). Versioned keys so a future
// schema bump can invalidate old fixtures without manual cache clearing.
//
// NOTE: fixtures here are clearly-labeled demo data. Per docs/DATABASE_BASELINE.md we
// must NOT treat any brand (青柠育见 etc.) as a system default tenant, so seed names
// are neutral "(示例)" placeholders rather than real brand projects.
import type { Column, Project } from '../types';

const PROJECTS_KEY = 'devw01_v1_projects';
const COLUMNS_KEY = 'devw01_v1_columns';

function nowIso(): string {
  return new Date().toISOString();
}

function read<T>(key: string, seed: T): T {
  try {
    const raw = localStorage.getItem(key);
    if (raw) return JSON.parse(raw) as T;
  } catch {
    // ignore parse errors and fall through to seed
  }
  localStorage.setItem(key, JSON.stringify(seed));
  return seed;
}

function write<T>(key: string, value: T): void {
  localStorage.setItem(key, JSON.stringify(value));
}

const seedProjects: Project[] = [
  {
    id: 'p_demo_1',
    name: '品牌内容工作台（示例）',
    slug: 'brand-content-demo',
    description: '青柠育见品牌内容生产的试点项目。',
    created_at: nowIso(),
    updated_at: nowIso(),
  },
  {
    id: 'p_demo_2',
    name: '多品牌矩阵（示例）',
    slug: 'multi-brand-demo',
    description: '跨品牌内容协同示例项目。',
    created_at: nowIso(),
    updated_at: nowIso(),
  },
  {
    id: 'p_demo_3',
    name: '内部培训素材（示例）',
    slug: 'internal-training-demo',
    description: '内部培训与案例沉淀示例项目。',
    created_at: nowIso(),
    updated_at: nowIso(),
  },
];

const seedColumns: Column[] = [
  {
    id: 'c_demo_1',
    project_id: 'p_demo_1',
    name: '安心小日常',
    slug: 'daily-comfort',
    description: '家庭日常安心场景内容。',
    sort_order: 1,
    created_at: nowIso(),
    updated_at: nowIso(),
  },
  {
    id: 'c_demo_2',
    project_id: 'p_demo_1',
    name: '爸妈在成长',
    slug: 'parents-growing',
    description: '长辈关怀与成长内容。',
    sort_order: 2,
    created_at: nowIso(),
    updated_at: nowIso(),
  },
  {
    id: 'c_demo_3',
    project_id: 'p_demo_2',
    name: '品牌故事',
    slug: 'brand-story',
    description: '品牌叙事与理念内容。',
    sort_order: 1,
    created_at: nowIso(),
    updated_at: nowIso(),
  },
];

export const mockDb = {
  getProjects(): Project[] {
    return read(PROJECTS_KEY, seedProjects);
  },
  setProjects(projects: Project[]): void {
    write(PROJECTS_KEY, projects);
  },
  getColumns(): Column[] {
    return read(COLUMNS_KEY, seedColumns);
  },
  setColumns(columns: Column[]): void {
    write(COLUMNS_KEY, columns);
  },
};
