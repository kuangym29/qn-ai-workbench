import { http } from './http';
import type { ApiResponse } from './types';

export interface Inspiration { id: number; project_id: number; body: string; note: string | null }
export interface Topic { id: number; title: string; description: string | null; content_column_id: number; status: 'pending' | 'parked'; is_backup: boolean; inspiration: Pick<Inspiration, 'id' | 'body'> | null; has_content_item: boolean; content_item_id: number | null }
export interface BranchSelection { channel: 'wechat_official' | 'wechat_channels'; format: 'image_post' | 'article' | 'video' }
export interface ContentBranch extends BranchSelection { id: number; project_id: number; content_item_id: number; channel_label: string; format_label: string; stopped_at: string | null }
export interface ContentItem { id: number; project_id: number; topic_id: number; content_column_id: number; title: string; core_message: string | null; story_line: string | null; topic: { id: number; title: string; inspiration: Pick<Inspiration, 'id' | 'body'> | null }; content_branches: ContentBranch[] }
export interface InProgressItem extends ContentItem { active_branches: ContentBranch[]; next_action: string }
export const BRANCH_OPTIONS: (BranchSelection & { label: string })[] = [
  { channel: 'wechat_official', format: 'image_post', label: '微信公众号 · 图片文章（小绿书）' },
  { channel: 'wechat_official', format: 'article', label: '微信公众号 · 图文文章' },
  { channel: 'wechat_channels', format: 'video', label: '微信视频号 · 视频' },
];
const base = (p: string | number) => `/api/projects/${Number(p)}`;
async function get<T>(url: string): Promise<T> { return (await http.get<ApiResponse<T>>(url)).data.data; }
async function post<T>(url: string, data: unknown): Promise<T> { return (await http.post<ApiResponse<T>>(url, data)).data.data; }
async function patch<T>(url: string, data: unknown): Promise<T> { return (await http.patch<ApiResponse<T>>(url, data)).data.data; }
export const v2Api = {
  inspirations: (p: string | number) => get<Inspiration[]>(`${base(p)}/inspirations`),
  createInspiration: (p: string | number, data: {body: string; note: string | null}) => post<Inspiration>(`${base(p)}/inspirations`, data),
  updateInspiration: (p: string | number, id: number, data: {body: string; note: string | null}) => patch<Inspiration>(`${base(p)}/inspirations/${id}`, data),
  topics: (p: string | number) => get<Topic[]>(`${base(p)}/topics`),
  createTopic: (p: string | number, data: {title: string; description: string | null; content_column_id: number; inspiration_id: number | null}) => post<Topic>(`${base(p)}/topics`, data),
  updateTopic: (p: string | number, id: number, data: Partial<Pick<Topic, 'title' | 'description' | 'status' | 'is_backup'>>) => patch<Topic>(`${base(p)}/topics/${id}`, data),
  start: (p: string | number, id: number, branches: BranchSelection[]) => post<ContentItem>(`${base(p)}/topics/${id}/start`, {branches}),
  items: (p: string | number) => get<ContentItem[]>(`${base(p)}/items`),
  item: (p: string | number, id: string | number) => get<ContentItem>(`${base(p)}/items/${id}`),
  updateItem: (p: string | number, id: number, data: Pick<ContentItem, 'title' | 'core_message' | 'story_line'>) => patch<ContentItem>(`${base(p)}/items/${id}`, data),
  addBranch: (p: string | number, id: number, data: BranchSelection) => post<ContentBranch>(`${base(p)}/items/${id}/branches`, data),
  stopBranch: (p: string | number, id: number, branch: number) => post<ContentBranch>(`${base(p)}/items/${id}/branches/${branch}/stop`, {}),
  progress: (p: string | number) => get<InProgressItem[]>(`${base(p)}/in-progress`),
};
