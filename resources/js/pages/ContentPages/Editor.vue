<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type {
  Column,
  ContentCopyRevision,
  ContentItem,
  ContentPage,
  CopyStatus,
  PageCopyFields,
  PageType,
  Project,
  Topic,
} from '../../api/types';
import { COPY_STATUS_LABELS, PAGE_TYPE_LABELS, PAGE_TYPES } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { contentItemsApi } from '../../api/contentItems';
import { contentPagesApi } from '../../api/contentPages';
import { is404, extractFieldErrors, firstErrorMessage, type FieldErrors } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import Badge from '../../components/Badge.vue';
import DuplicateReviewPanel from '../../components/DuplicateReviewPanel.vue';

const props = defineProps<{
  projectId: string;
  columnId: string;
  topicId: string;
  itemId: string;
}>();

// Scope helper shared by every API call below.
const scope = computed(() => ({
  projectId: props.projectId,
  columnId: props.columnId,
  topicId: props.topicId,
  itemId: props.itemId,
}));

// Which copy / note fields each page type is allowed to carry. appendDraft only ever sends
// these, so inherit behaviour (omitted = previous version) is preserved per page type.
const FIELDS_BY_TYPE: Record<PageType, (keyof PageCopyFields)[]> = {
  cover: ['column_label', 'cover_title', 'cover_subtitle', 'note'],
  content: ['page_title', 'page_small_text', 'note'],
  column_closing: ['closing_line', 'note'],
  fixed_back_cover: ['note'],
};

// Field label map for the editor + error display.
const FIELD_LABELS: Record<keyof PageCopyFields, string> = {
  column_label: '栏目标签',
  cover_title: '封面标题',
  cover_subtitle: '封面副标题',
  page_title: '页面标题',
  page_small_text: '页面小字',
  closing_line: '收尾文案',
  note: '备注',
};

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const loadError = ref('');

const project = ref<Project | null>(null);
const column = ref<Column | null>(null);
const topic = ref<Topic | null>(null);
const item = ref<ContentItem | null>(null);

// Working copy pages (page_no ASC from the API; local reorder is applied in-place).
const pages = ref<ContentPage[]>([]);
const revisions = ref<ContentCopyRevision[]>([]);
const currentRevision = ref<ContentCopyRevision | null>(null);

const selectedPageId = ref<number | null>(null);
// The editable draft form keeps every field as a plain string so textarea v-model stays
// type-safe; an empty string means "no value" (the server also accepts null on write, but
// we never need to distinguish them in the editor).
type DraftForm = Record<keyof PageCopyFields, string>;
function emptyDraftForm(): DraftForm {
  return {
    column_label: '',
    cover_title: '',
    cover_subtitle: '',
    page_title: '',
    page_small_text: '',
    closing_line: '',
    note: '',
  };
}
const draft = ref<DraftForm>(emptyDraftForm());
const draftErrors = ref<Record<string, string>>({});
const savingDraft = ref(false);

const pageForm = ref<{ page_no: number; page_type: PageType }>({ page_no: 1, page_type: 'cover' });
const pageErrors = ref<Record<string, string>>({});
const creatingPage = ref(false);

const changingTypeFor = ref<number | null>(null);
const savingOrder = ref(false);
const markingPending = ref(false);
const confirming = ref(false);
const confirmOpen = ref(false);

const revisionDetail = ref<ContentCopyRevision | null>(null);
const loadingRevision = ref(false);
// The panel owns its own fetch. The editor only asks it to re-read after the working
// copy changes, because a new working version changes both the query set and which
// older decisions are still valid.
const duplicateReviewRef = ref<InstanceType<typeof DuplicateReviewPanel> | null>(null);

// ---- derived view state -------------------------------------------------
const selectedPage = computed<ContentPage | null>(
  () => pages.value.find((p) => p.id === selectedPageId.value) ?? null,
);
const selectedIndex = computed(() =>
  pages.value.findIndex((p) => p.id === selectedPageId.value),
);
const maxVersionNo = computed(() => {
  let max = 0;
  for (const p of pages.value) {
    const v = p.latest_version?.version_no ?? 0;
    if (v > max) max = v;
  }
  return max;
});
const itemsUrl = `/projects/${props.projectId}/columns/${props.columnId}/topics/${props.topicId}/items`;

function copyStatusVariant(value: CopyStatus): 'default' | 'muted' | 'active' | 'planning' {
  if (value === 'confirmed') return 'active';
  if (value === 'editing' || value === 'pending_confirmation') return 'planning';
  return 'default';
}

function pageSummary(page: ContentPage): string {
  const v = page.latest_version;
  if (!v) return '（暂无版本）';
  switch (page.page_type) {
    case 'cover':
      return v.cover_title || v.column_label || '—';
    case 'content':
      return v.page_title || '—';
    case 'column_closing':
      return v.closing_line || '—';
    case 'fixed_back_cover':
      return v.note || '—';
  }
}

function fmtTime(s: string | null): string {
  if (!s) return '—';
  return s.replace('T', ' ').replace('Z', '').replace(/\.\d+$/, '');
}

function pageTypeLabel(t: string | null | undefined): string {
  if (!t) return '—';
  return PAGE_TYPE_LABELS[t as PageType] ?? t;
}

// ---- data loading -------------------------------------------------------
function flattenFieldErrors(e: unknown): Record<string, string> {
  const fe: FieldErrors = extractFieldErrors(e);
  const out: Record<string, string> = {};
  for (const k of Object.keys(fe)) {
    const msg = fe[k]?.[0];
    if (msg) out[k] = msg;
  }
  return out;
}

function handleScopeError(e: unknown): void {
  if (is404(e)) {
    toast.error('当前项目、栏目、选题、篇目或页面不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  loadError.value = firstErrorMessage(e) ?? '加载失败';
  status.value = 'error';
}

async function reloadData(): Promise<void> {
  const [it, working, current, revs] = await Promise.all([
    contentItemsApi.get(props.projectId, props.columnId, props.topicId, props.itemId),
    contentPagesApi.getWorkingCopy(scope.value),
    contentPagesApi.getCurrentRevision(scope.value),
    contentPagesApi.listRevisions(scope.value),
  ]);
  item.value = it;
  pages.value = working;
  currentRevision.value = current;
  revisions.value = revs;
  if (selectedPageId.value === null && pages.value.length > 0) {
    selectPage(pages.value[0].id);
  } else if (
    selectedPageId.value !== null &&
    !pages.value.some((p) => p.id === selectedPageId.value)
  ) {
    selectedPageId.value = pages.value.length ? pages.value[0].id : null;
    if (selectedPageId.value !== null) selectPage(selectedPageId.value);
  }
}

function refreshDuplicateReview(): void {
  duplicateReviewRef.value?.reload();
}

function nextDefaultPageNo(): number {
  let max = 0;
  for (const p of pages.value) if (p.page_no > max) max = p.page_no;
  return max + 1;
}

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    // NOTE (DEV-W04.1): Never auto-select the project from the URL. The server-side
    // ProjectContext (session) is the sole authority for the current scope. If the URL
    // project differs from the session project, the scope-bound calls below (columns /
    // topics / content-items / pages) return 404 and the existing scope UX redirects the
    // user to /projects. We must NOT silently switch the session here.
    const [list, col, top] = await Promise.all([
      projectsApi.list(),
      columnsApi.get(props.projectId, props.columnId),
      topicsApi.get(props.projectId, props.columnId, props.topicId),
    ]);
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = col;
    topic.value = top;
    await reloadData();
    pageForm.value = { page_no: nextDefaultPageNo(), page_type: 'cover' };
    status.value = 'ready';
  } catch (e) {
    handleScopeError(e);
  }
}

// ---- page selection + draft editing -------------------------------------
function selectPage(id: number): void {
  selectedPageId.value = id;
  const v = pages.value.find((p) => p.id === id)?.latest_version;
  draft.value = {
    column_label: v?.column_label ?? '',
    cover_title: v?.cover_title ?? '',
    cover_subtitle: v?.cover_subtitle ?? '',
    page_title: v?.page_title ?? '',
    page_small_text: v?.page_small_text ?? '',
    closing_line: v?.closing_line ?? '',
    note: v?.note ?? '',
  };
  draftErrors.value = {};
}

function buildDraftPayload(pageType: PageType): PageCopyFields {
  const payload: PageCopyFields = {};
  for (const key of FIELDS_BY_TYPE[pageType]) {
    payload[key] = draft.value[key];
  }
  return payload;
}

async function saveDraft(): Promise<void> {
  if (selectedPage.value === null) return;
  savingDraft.value = true;
  draftErrors.value = {};
  const page = selectedPage.value;
  try {
    await contentPagesApi.appendDraft(scope.value, page.id, buildDraftPayload(page.page_type));
    toast.success('已保存草稿（新增一个工作版本）');
    await reloadData();
    refreshDuplicateReview();
    if (selectedPageId.value !== null) selectPage(selectedPageId.value);
  } catch (e) {
    handleWriteError(e, 'draft');
  } finally {
    savingDraft.value = false;
  }
}

async function changePageType(pageId: number, newType: PageType): Promise<void> {
  changingTypeFor.value = pageId;
  try {
    await contentPagesApi.updatePageType(scope.value, pageId, newType);
    toast.success('页面类型已更新');
    await reloadData();
    refreshDuplicateReview();
    if (selectedPageId.value !== null) selectPage(selectedPageId.value);
  } catch (e) {
    handleWriteError(e, 'page');
  } finally {
    changingTypeFor.value = null;
  }
}

// ---- page lifecycle -----------------------------------------------------
async function createPage(): Promise<void> {
  creatingPage.value = true;
  pageErrors.value = {};
  try {
    const created = await contentPagesApi.createPage(scope.value, {
      page_no: pageForm.value.page_no,
      page_type: pageForm.value.page_type,
    });
    toast.success('页面已创建');
    await reloadData();
    selectPage(created.id);
    refreshDuplicateReview();
    pageForm.value = { page_no: nextDefaultPageNo(), page_type: 'cover' };
  } catch (e) {
    handleWriteError(e, 'page');
  } finally {
    creatingPage.value = false;
  }
}

function moveLocal(from: number, to: number): void {
  if (to < 0 || to >= pages.value.length) return;
  const arr = [...pages.value];
  const [moved] = arr.splice(from, 1);
  arr.splice(to, 0, moved);
  pages.value = arr;
}

async function saveOrder(): Promise<void> {
  savingOrder.value = true;
  try {
    const ids = pages.value.map((p) => p.id);
    await contentPagesApi.reorderPages(scope.value, ids);
    toast.success('排序已保存');
    await reloadData();
    if (selectedPageId.value !== null) selectPage(selectedPageId.value);
  } catch (e) {
    handleWriteError(e, 'page');
  } finally {
    savingOrder.value = false;
  }
}

async function markPending(): Promise<void> {
  if (!item.value) return;
  markingPending.value = true;
  try {
    await contentItemsApi.update(
      props.projectId,
      props.columnId,
      props.topicId,
      props.itemId,
      { copy_status: 'pending_confirmation' },
    );
    toast.success('已标记为待确认');
    await reloadData();
  } catch (e) {
    handleWriteError(e, 'item');
  } finally {
    markingPending.value = false;
  }
}

async function confirmFormal(): Promise<void> {
  confirming.value = true;
  try {
    const rev = await contentPagesApi.confirmCopy(scope.value);
    toast.success(`已生成正式文案版本（Revision ${rev.revision_no}）`);
    confirmOpen.value = false;
    await reloadData();
    refreshDuplicateReview();
  } catch (e) {
    if (is404(e)) {
      toast.error('当前项目、栏目、选题、篇目或页面不在当前会话作用域内，已返回项目列表');
      router.visit('/projects');
      return;
    }
    const fe = extractFieldErrors(e);
    const copyMsg = fe.copy?.[0];
    if (copyMsg) toast.error(copyMsg);
    else toast.error(firstErrorMessage(e) ?? '确认失败');
  } finally {
    confirming.value = false;
  }
}

async function openRevision(id: number): Promise<void> {
  loadingRevision.value = true;
  try {
    revisionDetail.value = await contentPagesApi.getRevision(scope.value, id);
  } catch (e) {
    handleScopeError(e);
  } finally {
    loadingRevision.value = false;
  }
}

function handleWriteError(e: unknown, kind: 'draft' | 'page' | 'item'): void {
  if (is404(e)) {
    toast.error('当前项目、栏目、选题、篇目或页面不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  const fe = flattenFieldErrors(e);
  if (kind === 'draft') draftErrors.value = fe;
  else if (kind === 'page') pageErrors.value = fe;
  if (Object.keys(fe).length === 0) {
    toast.error(firstErrorMessage(e) ?? '操作失败');
  }
}

function firstDraftError(): string {
  const keys = Object.keys(draftErrors.value);
  return keys.length ? draftErrors.value[keys[0]] : '';
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <Breadcrumb
      :items="[
        { label: project?.name ?? '项目', href: '/projects' },
        { label: column?.name ?? '小栏目', href: `/projects/${projectId}/columns` },
        {
          label: topic?.title ?? '选题',
          href: `/projects/${projectId}/columns/${columnId}/topics`,
        },
        { label: '篇目', href: itemsUrl },
        { label: '文案编辑' },
      ]"
    />

    <PageHeader
      title="文案编辑"
      description="逐页编写与确认整篇文案。工作稿为当前编辑版本，确认后生成不可覆盖的正式版本。"
    >
      <template #actions>
        <Link
          :href="itemsUrl"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回篇目
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" label="加载文案工作区…" />
    <ErrorState v-else-if="status === 'error'" :message="loadError" />

    <div v-else class="space-y-6">
      <!-- 篇目状态 + 工作稿 / 正式版本 对照 -->
      <div class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <h2 class="text-base font-semibold text-slate-900">
              篇目：{{ item?.title }}
            </h2>
            <Badge
              v-if="item"
              :variant="copyStatusVariant(item.copy_status)"
              :label="COPY_STATUS_LABELS[item.copy_status]"
            />
          </div>
          <div class="flex items-center gap-2">
            <button
              type="button"
              :disabled="item?.copy_status === 'confirmed' || markingPending"
              class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
              @click="markPending"
            >
              {{ markingPending ? '处理中…' : '标记待确认' }}
            </button>
            <button
              type="button"
              :disabled="confirming"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
              @click="confirmOpen = true"
            >
              {{ confirming ? '确认中…' : '确认正式文案' }}
            </button>
          </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <div class="rounded-md bg-slate-50 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">工作稿</p>
            <p class="mt-1 text-sm text-slate-700">
              共 {{ pages.length }} 页 · 当前最大版本 v{{ maxVersionNo || '—' }}
            </p>
            <p class="mt-1 text-xs text-slate-400">
              保存草稿会改变工作稿，不影响正式版本，直到再次确认。
            </p>
          </div>
          <div class="rounded-md bg-emerald-50 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">正式版本</p>
            <p v-if="currentRevision" class="mt-1 text-sm text-emerald-800">
              Revision {{ currentRevision.revision_no }} · 确认时间 {{ fmtTime(currentRevision.confirmed_at) }}
            </p>
            <p v-else class="mt-1 text-sm text-emerald-700">暂无正式版本</p>
          </div>
        </div>
      </div>

      <!-- 查重审核：Working Copy 阶段即可看到与同项目正式历史版本的重复候选 -->
      <DuplicateReviewPanel ref="duplicateReviewRef" :scope="scope" />

      <div class="grid gap-6 lg:grid-cols-3">
        <!-- 左栏：页面列表 / 新增 / 历史 -->
        <div class="space-y-6">
          <div class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-3">
              <h3 class="text-sm font-semibold text-slate-900">页面列表</h3>
            </div>
            <ul v-if="pages.length" class="divide-y divide-slate-100">
              <li
                v-for="(page, index) in pages"
                :key="page.id"
                class="cursor-pointer px-4 py-3"
                :class="page.id === selectedPageId ? 'bg-slate-50' : 'hover:bg-slate-50'"
                @click="selectPage(page.id)"
              >
                <div class="flex items-center justify-between gap-2">
                  <div class="flex min-w-0 items-center gap-2">
                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700">
                      {{ index + 1 }}
                    </span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                      {{ PAGE_TYPE_LABELS[page.page_type] }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1" @click.stop>
                    <button
                      type="button"
                      :disabled="index === 0 || pages.length <= 1"
                      class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                      @click="moveLocal(index, index - 1)"
                    >
                      上移
                    </button>
                    <button
                      type="button"
                      :disabled="index === pages.length - 1 || pages.length <= 1"
                      class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                      @click="moveLocal(index, index + 1)"
                    >
                      下移
                    </button>
                  </div>
                </div>
                <p class="mt-1 truncate text-sm text-slate-700">{{ pageSummary(page) }}</p>
                <div class="mt-1 flex items-center justify-between gap-2" @click.stop>
                  <span class="text-xs text-slate-400">
                    {{ page.latest_version ? `版本 v${page.latest_version.version_no}` : '暂无版本' }}
                  </span>
                  <select
                    :value="page.page_type"
                    :disabled="changingTypeFor === page.id"
                    class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 focus:border-slate-400 focus:outline-none"
                    @change="changePageType(page.id, ($event.target as HTMLSelectElement).value as PageType)"
                  >
                    <option v-for="t in PAGE_TYPES" :key="t" :value="t">
                      {{ PAGE_TYPE_LABELS[t] }}
                    </option>
                  </select>
                </div>
              </li>
            </ul>
            <p v-else class="px-4 py-6 text-center text-sm text-slate-400">还没有页面</p>

            <div v-if="pages.length > 1" class="border-t border-slate-100 px-4 py-3">
              <button
                type="button"
                :disabled="savingOrder"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                @click="saveOrder"
              >
                {{ savingOrder ? '保存中…' : '保存排序' }}
              </button>
            </div>
          </div>

          <!-- 新增页面 -->
          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-slate-900">新增页面</h3>
            <div class="space-y-3">
              <div>
                <label class="block text-xs font-medium text-slate-600">页码</label>
                <input
                  v-model.number="pageForm.page_no"
                  type="number"
                  min="1"
                  class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                />
                <p v-if="pageErrors.page_no" class="mt-1 text-xs text-rose-600">
                  {{ pageErrors.page_no }}
                </p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600">页面类型</label>
                <select
                  v-model="pageForm.page_type"
                  class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                >
                  <option v-for="t in PAGE_TYPES" :key="t" :value="t">
                    {{ PAGE_TYPE_LABELS[t] }}
                  </option>
                </select>
                <p v-if="pageErrors.page_type" class="mt-1 text-xs text-rose-600">
                  {{ pageErrors.page_type }}
                </p>
              </div>
              <button
                type="button"
                :disabled="creatingPage"
                class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                @click="createPage"
              >
                {{ creatingPage ? '创建中…' : '新增页面' }}
              </button>
            </div>
          </div>

          <!-- Revision 历史 -->
          <div class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-3">
              <h3 class="text-sm font-semibold text-slate-900">Revision 历史</h3>
            </div>
            <ul v-if="revisions.length" class="divide-y divide-slate-100">
              <li
                v-for="rev in revisions"
                :key="rev.id"
                class="flex items-center justify-between px-4 py-3"
              >
                <div>
                  <p class="text-sm font-medium text-slate-800">Revision {{ rev.revision_no }}</p>
                  <p class="text-xs text-slate-400">确认时间 {{ fmtTime(rev.confirmed_at) }}</p>
                </div>
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  @click="openRevision(rev.id)"
                >
                  查看
                </button>
              </li>
            </ul>
            <p v-else class="px-4 py-6 text-center text-sm text-slate-400">暂无正式记录</p>
          </div>
        </div>

        <!-- 右栏：页面编辑 / Revision 详情 -->
        <div class="lg:col-span-2">
          <div
            v-if="revisionDetail"
            class="rounded-lg border border-slate-200 bg-white p-5"
          >
            <div class="mb-4 flex items-center justify-between">
              <h3 class="text-base font-semibold text-slate-900">
                Revision {{ revisionDetail.revision_no }} 正式快照
              </h3>
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                @click="revisionDetail = null"
              >
                关闭
              </button>
            </div>
            <p class="mb-4 text-xs text-slate-400">
              确认时间 {{ fmtTime(revisionDetail.confirmed_at) }} · 只读，不可编辑或覆盖
            </p>
            <div v-if="revisionDetail.page_versions?.length" class="space-y-3">
              <div
                v-for="pv in revisionDetail.page_versions"
                :key="pv.id"
                class="rounded-md border border-slate-100 bg-slate-50 p-3"
              >
                <div class="mb-1 flex items-center gap-2 text-xs text-slate-500">
                  <span>第 {{ pv.page_no_snapshot }} 页</span>
                  <span class="rounded bg-slate-200 px-1.5 py-0.5 text-slate-600">
                    {{ pageTypeLabel(pv.page_type_snapshot) }}
                  </span>
                  <span>版本 v{{ pv.version_no }}</span>
                </div>
                <p v-if="pv.column_label" class="text-sm text-slate-700">栏目标签：{{ pv.column_label }}</p>
                <p v-if="pv.cover_title" class="text-sm text-slate-700">封面标题：{{ pv.cover_title }}</p>
                <p v-if="pv.cover_subtitle" class="text-sm text-slate-700">封面副标题：{{ pv.cover_subtitle }}</p>
                <p v-if="pv.page_title" class="text-sm text-slate-700">页面标题：{{ pv.page_title }}</p>
                <p v-if="pv.page_small_text" class="whitespace-pre-wrap text-sm text-slate-700">页面小字：{{ pv.page_small_text }}</p>
                <p v-if="pv.closing_line" class="whitespace-pre-wrap text-sm text-slate-700">收尾文案：{{ pv.closing_line }}</p>
                <p v-if="pv.note" class="whitespace-pre-wrap text-sm text-slate-500">备注：{{ pv.note }}</p>
              </div>
            </div>
            <p v-else class="text-sm text-slate-400">该修订暂无页面快照。</p>
          </div>

          <div
            v-else-if="selectedPage"
            class="rounded-lg border border-slate-200 bg-white p-5"
          >
            <div class="mb-4 flex items-center justify-between gap-2">
              <div>
                <h3 class="text-base font-semibold text-slate-900">
                  编辑页面 · 第 {{ selectedIndex + 1 }} 页 ·
                  {{ PAGE_TYPE_LABELS[selectedPage.page_type] }}
                </h3>
                <p class="mt-1 text-xs text-slate-400">
                  当前版本 v{{ selectedPage.latest_version?.version_no ?? '—' }} · 每次保存将形成新版本
                </p>
              </div>
            </div>

            <div class="space-y-4">
              <!-- cover -->
              <template v-if="selectedPage.page_type === 'cover'">
                <div>
                  <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.column_label }}</label>
                  <textarea v-model="draft.column_label" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                  <p v-if="draftErrors.column_label" class="mt-1 text-xs text-rose-600">{{ draftErrors.column_label }}</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.cover_title }}</label>
                  <textarea v-model="draft.cover_title" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                  <p v-if="draftErrors.cover_title" class="mt-1 text-xs text-rose-600">{{ draftErrors.cover_title }}</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.cover_subtitle }}</label>
                  <textarea v-model="draft.cover_subtitle" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                  <p v-if="draftErrors.cover_subtitle" class="mt-1 text-xs text-rose-600">{{ draftErrors.cover_subtitle }}</p>
                </div>
              </template>

              <!-- content -->
              <template v-else-if="selectedPage.page_type === 'content'">
                <div>
                  <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.page_title }}</label>
                  <textarea v-model="draft.page_title" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                  <p v-if="draftErrors.page_title" class="mt-1 text-xs text-rose-600">{{ draftErrors.page_title }}</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.page_small_text }}</label>
                  <textarea v-model="draft.page_small_text" rows="4" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                  <p v-if="draftErrors.page_small_text" class="mt-1 text-xs text-rose-600">{{ draftErrors.page_small_text }}</p>
                </div>
              </template>

              <!-- column_closing -->
              <template v-else-if="selectedPage.page_type === 'column_closing'">
                <div>
                  <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.closing_line }}</label>
                  <textarea v-model="draft.closing_line" rows="4" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                  <p v-if="draftErrors.closing_line" class="mt-1 text-xs text-rose-600">{{ draftErrors.closing_line }}</p>
                </div>
              </template>

              <!-- fixed_back_cover -->
              <template v-else>
                <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
                  固定封底通常无需填写正文，仅可补充备注。
                </p>
              </template>

              <!-- note (all types except fixed_back_cover already include it; show for every type) -->
              <div>
                <label class="block text-sm font-medium text-slate-700">{{ FIELD_LABELS.note }}</label>
                <textarea v-model="draft.note" rows="3" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"></textarea>
                <p v-if="draftErrors.note" class="mt-1 text-xs text-rose-600">{{ draftErrors.note }}</p>
              </div>

              <div class="flex items-center gap-3 pt-2">
                <button
                  type="button"
                  :disabled="savingDraft"
                  class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                  @click="saveDraft"
                >
                  {{ savingDraft ? '保存中…' : '保存草稿' }}
                </button>
                <span v-if="Object.keys(draftErrors).length" class="text-xs text-rose-600">
                  {{ firstDraftError() }}
                </span>
              </div>
            </div>
          </div>

          <div
            v-else
            class="flex items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-sm text-slate-400"
          >
            从左侧选择页面开始编辑，或新增一个页面。
          </div>
        </div>
      </div>
    </div>

    <!-- 确认正式文案弹窗 -->
    <div
      v-if="confirmOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
      @click.self="confirmOpen = false"
    >
      <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h3 class="text-base font-semibold text-slate-900">确认正式文案</h3>
        <p class="mt-2 text-sm text-slate-600">
          确认后将生成一份完整正式文案版本。后续修改会产生新的工作版本，不会覆盖本次正式记录。
        </p>
        <div class="mt-5 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="confirmOpen = false"
          >
            取消
          </button>
          <button
            type="button"
            :disabled="confirming"
            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            @click="confirmFormal"
          >
            {{ confirming ? '确认中…' : '确认生成' }}
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
