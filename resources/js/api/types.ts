// Shared domain types for QN AI 内容工作台 Lite V1.0.
// Project, ContentColumn, Topic, ContentItem (DEV-W01..DEV-W03) and ContentPage /
// ContentCopyRevision (DEV-007A, wired in DEV-W04) and ProductionTask / ChannelTask
// (DEV-009A / DEV-W05, wired in DEV-W06) are modeled here.

// DEV-003 / DEV-005 wrap every response as {"data": ...}. Mirror that envelope here so the
// API adapter can unpack it in one place and never leak the wrapper into the UI.
export interface ApiResponse<T> {
  data: T;
}

export interface Project {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Column {
  id: number;
  project_id: number;
  name: string;
  slug: string;
  description: string | null;
  sort_order: number;
  created_at: string | null;
  updated_at: string | null;
}

// Topic (DEV-005). Always scoped to Project → Column. The server owns project_id and
// content_column_id (they are "prohibited" on write), so they are read-only here.
export interface Topic {
  id: number;
  project_id: number;
  content_column_id: number;
  title: string;
  description: string | null;
  created_at: string | null;
  updated_at: string | null;
}

// DEV-004 status normalization: these four values are the ONLY legal copy statuses.
// Never widen this to an arbitrary string — the backend CopyStatus enum is the contract,
// so 'draft' / 'completed' / 'approved' must never appear anywhere in the UI.
export type CopyStatus = 'not_started' | 'editing' | 'pending_confirmation' | 'confirmed';

// The subset a normal ContentItem form may PUT/PATCH. `confirmed` is intentionally EXCLUDED:
// it is only ever produced by the formal copy-confirmation flow in the ContentPage editor.
// DEV-007A.1 already rejects `confirmed` on a plain PATCH with 422; this type makes the
// same rule enforceable at compile time. (Read models keep the full `CopyStatus`.)
export type EditableCopyStatus = Exclude<CopyStatus, 'confirmed'>;

export const COPY_STATUSES: CopyStatus[] = [
  'not_started',
  'editing',
  'pending_confirmation',
  'confirmed',
];

// Chinese display labels. The submitted/returned value always stays the English formal
// value (e.g. 'pending_confirmation'); only the label is localized.
export const COPY_STATUS_LABELS: Record<CopyStatus, string> = {
  not_started: '未开始',
  editing: '编辑中',
  pending_confirmation: '待确认',
  confirmed: '已确认',
};

// ContentItem (DEV-005). Always scoped to Project → Column → Topic. The server owns
// project_id / content_column_id / topic_id, so they are read-only here.
export interface ContentItem {
  id: number;
  project_id: number;
  content_column_id: number;
  topic_id: number;
  title: string;
  copy_status: CopyStatus;
  created_at: string | null;
  updated_at: string | null;
}

// DEV-007A — ContentPage / CopyRevision domain types.
//
// A ContentPage is a stable page identity inside a ContentItem. Its copy lives in versions;
// `latest_version` is the version with the max version_no (a draft or a formal snapshot).
export type PageType = 'cover' | 'content' | 'column_closing' | 'fixed_back_cover';

export const PAGE_TYPES: PageType[] = [
  'cover',
  'content',
  'column_closing',
  'fixed_back_cover',
];

export const PAGE_TYPE_LABELS: Record<PageType, string> = {
  cover: '封面',
  content: '正文',
  column_closing: '栏目收尾',
  fixed_back_cover: '固定封底',
};

// The seven copy / note fields a page version may carry. All optional on write; omitted
// fields inherit the previous version (DEV-007A appendDraft). Unknown fields are rejected
// with 422, so only these keys are ever submitted.
export interface PageCopyFields {
  column_label?: string | null;
  cover_title?: string | null;
  cover_subtitle?: string | null;
  page_title?: string | null;
  page_small_text?: string | null;
  closing_line?: string | null;
  note?: string | null;
}

// A single page version (draft or formal snapshot). For drafts, copy_revision_id and the
// two snapshot fields are null. For a formal revision's page_versions they are populated.
export interface ContentPageVersion extends PageCopyFields {
  id: number;
  project_id: number;
  content_item_id: number;
  content_page_id: number;
  version_no: number;
  copy_revision_id: number | null;
  page_no_snapshot: number | null;
  page_type_snapshot: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ContentPage {
  id: number;
  project_id: number;
  content_item_id: number;
  page_no: number;
  page_type: PageType;
  latest_version: ContentPageVersion | null;
  created_at: string | null;
  updated_at: string | null;
}

// A formal revision. `page_versions` is present on detail / current / confirm responses,
// but absent from the list response (revision_no DESC).
export interface ContentCopyRevision {
  id: number;
  project_id: number;
  content_item_id: number;
  revision_no: number;
  confirmed_at: string | null;
  page_versions?: ContentPageVersion[];
}

// Inputs for create/update. `id`, ownership keys and timestamps are server/store managed.
export interface ProjectInput {
  name: string;
  slug: string;
  description?: string | null;
}

export interface ColumnInput {
  name: string;
  slug: string;
  description?: string | null;
  sort_order?: number;
}

export interface TopicInput {
  title: string;
  description?: string | null;
}

export interface ContentItemInput {
  title: string;
  // Omitted on create on purpose: the server defaults a new item to 'not_started'.
  // Only the three mutable states are acceptable here — `confirmed` is excluded (see
  // EditableCopyStatus); it can only be set by the copy editor's formal confirm flow.
  copy_status?: EditableCopyStatus;
}

// ---------------------------------------------------------------------------
// Production / Channel (DEV-009A ProductionTask + DEV-W05 ChannelTask, wired in DEV-W06)
// ---------------------------------------------------------------------------
//
// The backend stores these as plain strings cast to PHP string-backed enums; the four
// status dimensions stay strictly separate and are NEVER inferred from one another.
// These unions mirror the enums exactly — do not widen them to arbitrary strings.

export type ArtworkStatus =
  | 'not_applicable'
  | 'not_started'
  | 'in_progress'
  | 'pending_review'
  | 'approved';

export type VideoStatus =
  | 'not_applicable'
  | 'not_started'
  | 'in_progress'
  | 'pending_review'
  | 'approved';

export type PublishStatus = 'unpublished' | 'scheduled' | 'published';

export type Channel = 'wechat_official' | 'wechat_channels';

export const ARTWORK_STATUSES: ArtworkStatus[] = [
  'not_applicable',
  'not_started',
  'in_progress',
  'pending_review',
  'approved',
];

// WeChat Channels video stages. 'not_applicable' is intentionally excluded: the video
// account must never be parked in a no-video state (the server rejects it with 422).
export const VIDEO_STATUSES: VideoStatus[] = [
  'not_started',
  'in_progress',
  'pending_review',
  'approved',
];

export const PUBLISH_STATUSES: PublishStatus[] = ['unpublished', 'scheduled', 'published'];

export const CHANNELS: Channel[] = ['wechat_official', 'wechat_channels'];

export const ARTWORK_STATUS_LABELS: Record<ArtworkStatus, string> = {
  not_applicable: '不适用',
  not_started: '未开始',
  in_progress: '制作中',
  pending_review: '待审核',
  approved: '已通过',
};

export const VIDEO_STATUS_LABELS: Record<VideoStatus, string> = {
  not_applicable: '不适用',
  not_started: '未开始',
  in_progress: '制作中',
  pending_review: '待审核',
  approved: '已通过',
};

export const PUBLISH_STATUS_LABELS: Record<PublishStatus, string> = {
  unpublished: '未发布',
  scheduled: '已排期',
  published: '已发布',
};

export const CHANNEL_LABELS: Record<Channel, string> = {
  wechat_official: '微信公众号',
  wechat_channels: '微信视频号',
};

// DEV-009A ProductionTaskResource.  is nullable in the database for
// legacy rows, and  is computed by the SERVER by comparing the
// bound revision id against the item's current max-revision id — never re-derive it here.
export interface ProductionTask {
  id: number;
  project_id: number;
  content_item_id: number;
  copy_revision_id: number | null;
  copy_revision_no: number | null;
  artwork_status: ArtworkStatus;
  is_copy_revision_current: boolean;
  created_at: string | null;
  updated_at: string | null;
}

// DEV-W05 ChannelTaskResource.  /  are UTC ISO-8601 strings
// (or null);  again comes from the server.
export interface ChannelTask {
  id: number;
  project_id: number;
  production_task_id: number;
  channel: Channel;
  video_status: VideoStatus;
  publish_status: PublishStatus;
  scheduled_at: string | null;
  published_at: string | null;
  production_copy_revision_id: number | null;
  production_copy_revision_no: number | null;
  artwork_status: ArtworkStatus;
  is_production_copy_current: boolean;
  created_at: string | null;
  updated_at: string | null;
}

// DEV-W05 restart-with-current-copy returns both halves of the reset in one payload.
export interface ProductionRestartResult {
  production: ProductionTask;
  channels: ChannelTask[];
}

// ---------------------------------------------------------------------------
// SourceReference (DEV-008A data layer, DEV-W07 HTTP API + UI)
// ---------------------------------------------------------------------------
//
// Provenance records: a SourceReference is a *pointer* to a file under the brand source
// root, never a copy of its content. The five roles split into two scopes:
//   * item-scoped    → final_image_copy, source_script            (content_item_id set)
//   * project-scoped → content_ledger, closing_line_registry,
//                      navigation_index                            (content_item_id null)
// The scope split is owned by the backend (SourceRole::isContentItemScoped()); these
// helpers only mirror it for rendering and for picking which form to show.

export type SourceRole =
  | 'final_image_copy'
  | 'source_script'
  | 'content_ledger'
  | 'closing_line_registry'
  | 'navigation_index';

export type SourceAuthority = 'authoritative' | 'evidence' | 'index' | 'reference';

export const SOURCE_ROLES: SourceRole[] = [
  'final_image_copy',
  'source_script',
  'content_ledger',
  'closing_line_registry',
  'navigation_index',
];

/** Roles reachable through the Project-level API/UI. */
export const PROJECT_SCOPED_SOURCE_ROLES: SourceRole[] = [
  'content_ledger',
  'closing_line_registry',
  'navigation_index',
];

/** Roles reachable through the per-ContentItem API/UI. */
export const ITEM_SCOPED_SOURCE_ROLES: SourceRole[] = ['final_image_copy', 'source_script'];

export const SOURCE_ROLE_LABELS: Record<SourceRole, string> = {
  final_image_copy: '最终上图文案',
  source_script: '来源脚本',
  content_ledger: '内容台账',
  closing_line_registry: '收尾句台账',
  navigation_index: '导航索引',
};

export const SOURCE_AUTHORITY_LABELS: Record<SourceAuthority, string> = {
  authoritative: '权威源',
  evidence: '证据源',
  index: '索引',
  reference: '参考源',
};

// Mirrors SourceRole::isContentItemScoped() on the backend.
export function isItemScopedRole(role: SourceRole): boolean {
  return role === 'final_image_copy' || role === 'source_script';
}

export interface SourceReference {
  id: number;
  project_id: number;
  content_item_id: number | null;
  role: SourceRole;
  authority: SourceAuthority;
  source_path: string;
  note: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Create/update payload: ownership keys and authority are server-derived. */
export interface SourceReferenceInput {
  role: SourceRole;
  source_path: string;
  note?: string | null;
}
