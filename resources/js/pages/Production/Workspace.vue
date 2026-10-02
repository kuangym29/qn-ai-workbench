<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type {
  ArtworkStatus,
  Channel,
  ChannelTask,
  Column,
  ContentCopyRevision,
  ContentItem,
  CopyStatus,
  ProductionTask,
  Project,
  PublishStatus,
  Topic,
  VideoStatus,
} from '../../api/types';
import {
  ARTWORK_STATUS_LABELS,
  CHANNEL_LABELS,
  COPY_STATUS_LABELS,
  PUBLISH_STATUS_LABELS,
  VIDEO_STATUS_LABELS,
} from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { contentItemsApi } from '../../api/contentItems';
import { contentPagesApi } from '../../api/contentPages';
import { productionTasksApi } from '../../api/productionTasks';
import { channelTasksApi, type PublishUpdate } from '../../api/channelTasks';
import { is404, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import EmptyState from '../../components/EmptyState.vue';
import Badge from '../../components/Badge.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import ProductionAssets from '../../components/ProductionAssets.vue';
import { assetsApi } from '../../api/assets';
import type {
  AssetDetail,
  AssetVersionAppendInput,
  AssetWorkspace,
} from '../../api/types';

const props = defineProps<{
  projectId: string;
  columnId: string;
  topicId: string;
  itemId: string;
}>();

const scope = computed(() => ({
  projectId: props.projectId,
  columnId: props.columnId,
  topicId: props.topicId,
  itemId: props.itemId,
}));

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const loadError = ref('');

const project = ref<Project | null>(null);
const column = ref<Column | null>(null);
const topic = ref<Topic | null>(null);
const item = ref<ContentItem | null>(null);
const currentRevision = ref<ContentCopyRevision | null>(null);
const production = ref<ProductionTask | null>(null);
const channels = ref<ChannelTask[]>([]);

// Shared visual assets. `assetWorkspace` is only loaded once a production task exists —
// without one there is nothing to pin assets to, and the API does not apply.
const assetWorkspace = ref<AssetWorkspace | null>(null);
const assetLoading = ref(false);
const assetError = ref('');
const assetSaving = ref(false);
const assetDetail = ref<AssetDetail | null>(null);
const assetDetailLoading = ref(false);

// Independent busy flags so one in-flight action never blocks or double-fires another.
const creatingProduction = ref(false);
const updatingArtwork = ref(false);
const switchingCopy = ref(false);
const restartingProduction = ref(false);
const creatingChannel = ref<Channel | null>(null);
const updatingVideo = ref<Channel | null>(null);
const updatingPublish = ref<Channel | null>(null);

type ConfirmKind = 'artwork-approved' | 'video-approved' | 'use-current-copy' | 'restart' | 'publish';
const confirm = ref<{
  kind: ConfirmKind;
  open: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  danger: boolean;
  channel: Channel | null;
}>({ kind: 'artwork-approved', open: false, title: '', message: '', confirmLabel: '确认', danger: false, channel: null });

// Per-channel scheduling input. Kept as the raw `datetime-local` string so the user's
// half-typed value is never clobbered by a refresh.
const scheduleInput = ref<Record<Channel, string>>({
  wechat_official: '',
  wechat_channels: '',
});
const publishedAtInput = ref<Record<Channel, string>>({
  wechat_official: '',
  wechat_channels: '',
});

const itemsUrl = computed(
  () => `/projects/${props.projectId}/columns/${props.columnId}/topics/${props.topicId}/items`,
);
const copyUrl = computed(
  () => `/projects/${props.projectId}/columns/${props.columnId}/topics/${props.topicId}/items/${props.itemId}/copy`,
);

// ---- derived state ------------------------------------------------------

const official = computed<ChannelTask | null>(
  () => channels.value.find((c) => c.channel === 'wechat_official') ?? null,
);
const videoChannel = computed<ChannelTask | null>(
  () => channels.value.find((c) => c.channel === 'wechat_channels') ?? null,
);

const hasChannelTasks = computed(() => channels.value.length > 0);
const anyPublished = computed(() => channels.value.some((c) => c.publish_status === 'published'));

/** Server-authoritative: never re-derive "current" from copy_status. */
const productionCurrent = computed(() => production.value?.is_copy_revision_current === true);
const productionStale = computed(() => production.value !== null && !production.value.is_copy_revision_current);

/** Mirrors the server gate for creating a channel task (artwork approved + current). */
const canCreateChannel = computed(
  () =>
    production.value !== null &&
    production.value.artwork_status === 'approved' &&
    productionCurrent.value,
);
const channelCreateBlockedReason = computed(() => {
  if (production.value === null) return '请先开始共享图稿制作。';
  if (production.value.artwork_status !== 'approved') return '共享图稿需先审核通过，才能创建渠道任务。';
  if (!productionCurrent.value) return '正式文案已有新版本，请先处理图稿版本再创建渠道任务。';
  return '';
});

/**
 * Artwork approval gate (mirrors DEV-009A). The server requires BOTH a confirmed copy and
 * a production task that is still bound to the current formal revision. A confirmed
 * revision is not enough on its own: while a newer revision exists the task is stale and
 * the artwork must be reworked before it can be approved.
 */
const artworkApproveBlockedReason = computed(() => {
  if (!productionCurrent.value) return '正式文案已有新版本，请先处理图稿版本。';
  if (item.value !== null && item.value.copy_status !== 'confirmed') {
    return '当前文案存在尚未正式确认的修改，请先完成文案确认。';
  }
  return '';
});
const canApproveArtwork = computed(
  () =>
    production.value !== null &&
    production.value.artwork_status === 'pending_review' &&
    artworkApproveBlockedReason.value === '',
);

const copyConfirmed = computed(() => item.value !== null && item.value.copy_status === 'confirmed');

/**
 * Stale-repair routing (DEV-009A use-current-copy vs DEV-W05 restart-with-current-copy).
 * A / B use use-current-copy, C / D use restart, E is always blocked. Both paths require a
 * confirmed copy on the server side, so an unconfirmed copy must disable both.
 */
const staleRepairBlockedReason = computed(() => {
  // E — a published channel is a historical fact and can never be reset.
  if (anyPublished.value) return '已有渠道正式发布，不能重置生产链。已发布记录是历史事实。';
  if (!copyConfirmed.value) return '请先完成当前文案确认，再切换制作任务到最新正式版本。';
  // A / B — no channel task yet, so DEV-009A use-current-copy is the right action.
  if (!hasChannelTasks.value) return '';
  return '';
});
/** A / B: no channel task + confirmed copy → use-current-copy is executable. */
const canUseCurrentCopy = computed(
  () => productionStale.value && !hasChannelTasks.value && staleRepairBlockedReason.value === '',
);
/** C / D: channel tasks exist + confirmed copy + nothing published → restart is executable. */
const canRestart = computed(
  () => productionStale.value && hasChannelTasks.value && staleRepairBlockedReason.value === '',
);

/** Shared publish gate: artwork approved + production current. */
function publishBlockedReason(channel: ChannelTask): string {
  if (channel.publish_status === 'published') return '';
  if (channel.artwork_status !== 'approved') return '共享图稿需先审核通过。';
  if (!channel.is_production_copy_current) return '正式文案已有新版本，请先处理图稿版本。';
  if (channel.channel === 'wechat_channels' && channel.video_status !== 'approved') {
    return '请先完成视频审核。';
  }
  return '';
}
function canSchedule(channel: ChannelTask): boolean {
  return channel.publish_status !== 'published' && publishBlockedReason(channel) === '';
}
function canPublishNow(channel: ChannelTask): boolean {
  return canSchedule(channel);
}

/** Video approval is blocked on stale production; intermediate stages stay allowed. */
function videoApproveBlockedReason(channel: ChannelTask): string {
  if (channel.is_production_copy_current) return '';
  return '共享图稿基于旧版正式文案，不能完成视频终审。';
}
function canApproveVideo(channel: ChannelTask): boolean {
  return channel.video_status === 'pending_review' && videoApproveBlockedReason(channel) === '';
}

// ---- formatting ---------------------------------------------------------

/**
 * Format a UTC ISO string into the browser's local timezone.
 * `Intl.DateTimeFormat` is used deliberately — string slicing would only relabel the
 * UTC wall clock without converting it.
 */
function fmtLocal(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d);
}

const timezoneNote = computed(() => {
  try {
    return `时间按当前浏览器本地时区显示（${Intl.DateTimeFormat().resolvedOptions().timeZone}）。`;
  } catch {
    return '时间按当前浏览器本地时区显示。';
  }
});

/**
 * Convert a `datetime-local` value (browser local, no timezone) to a UTC ISO string.
 * Returns null for empty input and guards against Invalid Date.
 */
function localInputToIso(value: string): string | null {
  if (!value) return null;
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return null;
  return d.toISOString();
}

function copyVariant(value: CopyStatus): 'default' | 'muted' | 'active' | 'planning' {
  if (value === 'confirmed') return 'active';
  if (value === 'editing' || value === 'pending_confirmation') return 'planning';
  return 'default';
}
function artworkVariant(value: ArtworkStatus): 'default' | 'muted' | 'active' | 'planning' | 'danger' {
  if (value === 'approved') return 'active';
  if (value === 'in_progress' || value === 'pending_review') return 'planning';
  if (value === 'not_applicable') return 'muted';
  return 'default';
}
function videoVariant(value: VideoStatus): 'default' | 'muted' | 'active' | 'planning' | 'danger' {
  if (value === 'approved') return 'active';
  if (value === 'in_progress' || value === 'pending_review') return 'planning';
  if (value === 'not_applicable') return 'muted';
  return 'default';
}
function publishVariant(value: PublishStatus): 'default' | 'muted' | 'active' | 'planning' | 'danger' {
  if (value === 'published') return 'active';
  if (value === 'scheduled') return 'planning';
  return 'default';
}

// ---- error handling -----------------------------------------------------

/** 404 means the scope does not match the server session — never auto-select a project. */
// 404 means the scope does not match the server session. We never auto-select a project;
// the user is returned to the project list instead.
function handleScopeRedirect(e: unknown): boolean {
  if (!is404(e)) return false;
  toast.error('当前项目、栏目、选题或篇目不在当前会话作用域内，已返回项目列表');
  router.visit('/projects');
  return true;
}

/**
 * Initial page load only. A non-404 failure replaces the workspace with ErrorState,
 * because there is nothing meaningful to show yet.
 */
function handleLoadError(e: unknown): void {
  if (handleScopeRedirect(e)) return;
  loadError.value = firstErrorMessage(e) ?? '加载失败';
  status.value = 'error';
}

/**
 * Every write action. A business gate rejection (422 etc.) must NOT tear down the page:
 * it only surfaces the server's real message and keeps the current state on screen so
 * the user can adjust and retry. Only a scope 404 leaves the page.
 */
function handleActionError(e: unknown): void {
  if (handleScopeRedirect(e)) return;
  toast.error(firstErrorMessage(e) ?? '操作失败');
}

// ---- data loading -------------------------------------------------------

/**
 * Load the shared visual asset matrix.
 *
 * Only called when a production task exists. Asset versions are pinned to a copy
 * revision, so `use-current-copy` and `restart-with-current-copy` change what is
 * "current" — going through reload() keeps the displayed versions honest.
 *
 * A 404 here means the scope no longer matches the server session, so it reuses the
 * shared scope handling. A 422 (or any other failure) is confined to this section and
 * must never tear down the whole production workspace.
 */
async function loadAssets(): Promise<void> {
  if (production.value === null) {
    assetWorkspace.value = null;
    assetError.value = '';
    return;
  }

  assetLoading.value = true;
  assetError.value = '';
  try {
    assetWorkspace.value = await assetsApi.workspace(scope.value);
  } catch (e) {
    if (is404(e)) {
      assetWorkspace.value = null;
      handleActionError(e);
      return;
    }
    assetError.value = firstErrorMessage(e) ?? '加载视觉资产失败';
  } finally {
    assetLoading.value = false;
  }
}

async function reload(): Promise<void> {
  const [it, current, productionTask] = await Promise.all([
    contentItemsApi.get(props.projectId, props.columnId, props.topicId, props.itemId),
    contentPagesApi.getCurrentRevision(scope.value),
    productionTasksApi.get(scope.value),
  ]);
  item.value = it;
  currentRevision.value = current;
  production.value = productionTask;

  // The channel endpoint 404s without a production task, so only call it when present.
  channels.value = productionTask === null ? [] : await channelTasksApi.list(scope.value);

  // Same for assets: they hang off the production task.
  await loadAssets();
}

/**
 * Register a new asset version. On success we always re-read the workspace from the
 * server rather than patching a local version number.
 */
async function appendAssetVersion(payload: AssetVersionAppendInput): Promise<void> {
  assetSaving.value = true;
  try {
    await assetsApi.appendVersion(scope.value, payload);
    toast.success('已登记资产版本');
    await loadAssets();
  } catch (e) {
    // 422 keeps the dialog open with the user's input intact (the child resets only on open).
    handleActionError(e);
  } finally {
    assetSaving.value = false;
  }
}

/** Load the read-only version history for one slot. */
async function openAssetHistory(assetId: number): Promise<void> {
  assetDetailLoading.value = true;
  try {
    assetDetail.value = await assetsApi.detail(scope.value, assetId);
  } catch (e) {
    handleActionError(e);
  } finally {
    assetDetailLoading.value = false;
  }
}

function closeAssetHistory(): void {
  assetDetail.value = null;
}

/** A legacy production task with no pinned revision cannot accept registrations. */
const assetRegistrationDisabled = computed(
  () => production.value !== null && production.value.copy_revision_id === null,
);
const assetRegistrationDisabledReason = '此历史制作任务尚未绑定正式文案版本，无法登记资产。';

/** Format an Asset version's file size for display. */
function formatAssetBytes(size: number | null): string {
  if (size === null || !Number.isFinite(size)) return '';
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    const list = await projectsApi.list();
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = await columnsApi.get(props.projectId, props.columnId);
    topic.value = await topicsApi.get(props.projectId, props.columnId, props.topicId);
    await reload();
    status.value = 'ready';
  } catch (e) {
    handleLoadError(e);
  }
}

// ---- artwork workflow ---------------------------------------------------

function askArtworkApproved(): void {
  confirm.value = {
    kind: 'artwork-approved',
    open: true,
    title: '审核通过',
    message: '确认图稿已经审核完成？通过后即可创建公众号或视频号渠道任务。',
    confirmLabel: '审核通过',
    danger: false,
    channel: null,
  };
}

async function setArtwork(status: ArtworkStatus): Promise<void> {
  updatingArtwork.value = true;
  try {
    await productionTasksApi.updateArtwork(scope.value, status);
    toast.success(`图稿状态已更新为「${ARTWORK_STATUS_LABELS[status]}」`);
    confirm.value.open = false;
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    updatingArtwork.value = false;
  }
}

// ---- production lifecycle ----------------------------------------------

async function createProduction(): Promise<void> {
  creatingProduction.value = true;
  try {
    await productionTasksApi.create(scope.value);
    toast.success('已开始共享图稿制作');
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    creatingProduction.value = false;
  }
}

function askUseCurrentCopy(): void {
  confirm.value = {
    kind: 'use-current-copy',
    open: true,
    title: '切换到最新正式文案',
    message:
      '切换后图稿状态会重置为「未开始」，旧正式文案与历史 Revision 不会删除。',
    confirmLabel: '切换',
    danger: true,
    channel: null,
  };
}

async function useCurrentCopy(): Promise<void> {
  switchingCopy.value = true;
  try {
    await productionTasksApi.useCurrentCopy(scope.value);
    toast.success('已切换到最新正式文案');
    confirm.value.open = false;
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    switchingCopy.value = false;
  }
}

function askRestart(): void {
  confirm.value = {
    kind: 'restart',
    open: true,
    title: '按最新文案重新开始',
    message: [
      '该操作会：',
      '1. 将制作任务绑定到最新正式文案；',
      '2. 图稿状态重置为「未开始」；',
      '3. 重置公众号 / 视频号未发布任务的状态与时间；',
      '4. 渠道任务本身不会删除；',
      '5. 正式文案历史不会删除。',
    ].join('\n'),
    confirmLabel: '重新开始',
    danger: true,
    channel: null,
  };
}

async function restart(): Promise<void> {
  restartingProduction.value = true;
  try {
    await channelTasksApi.restartWithCurrentCopy(scope.value);
    toast.success('已按最新文案重新开始生产');
    confirm.value.open = false;
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    restartingProduction.value = false;
  }
}

function onConfirm(): void {
  if (confirm.value.kind === 'artwork-approved') {
    void setArtwork('approved');
    return;
  }
  if (confirm.value.kind === 'publish' && confirm.value.channel !== null) {
    void markPublished(confirm.value.channel);
    return;
  }
  if (confirm.value.kind === 'use-current-copy') {
    void useCurrentCopy();
    return;
  }
  if (confirm.value.kind === 'restart') {
    void restart();
    return;
  }
  if (confirm.value.kind === 'video-approved' && confirm.value.channel !== null) {
    void setVideo(confirm.value.channel, 'approved');
  }
}

// ---- channel lifecycle --------------------------------------------------

async function createChannel(channel: Channel): Promise<void> {
  creatingChannel.value = channel;
  try {
    await channelTasksApi.create(scope.value, channel);
    toast.success(`已创建${CHANNEL_LABELS[channel]}任务`);
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    creatingChannel.value = null;
  }
}

// ---- video workflow -----------------------------------------------------

function askVideoApproved(channel: Channel): void {
  confirm.value = {
    kind: 'video-approved',
    open: true,
    title: '视频审核通过',
    message: '确认视频已审核完成？通过后即可为微信视频号设置排期与发布。',
    confirmLabel: '审核通过',
    danger: false,
    channel,
  };
}

async function setVideo(channel: Channel, videoStatus: VideoStatus): Promise<void> {
  updatingVideo.value = channel;
  try {
    await channelTasksApi.updateVideo(scope.value, channel, videoStatus);
    toast.success(`视频状态已更新为「${VIDEO_STATUS_LABELS[videoStatus]}」`);
    confirm.value.open = false;
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    updatingVideo.value = null;
  }
}

// ---- publish workflow ---------------------------------------------------

async function setSchedule(channel: Channel): Promise<void> {
  const iso = localInputToIso(scheduleInput.value[channel]);
  if (iso === null) {
    toast.error('请选择有效的发布排期时间');
    return;
  }
  updatingPublish.value = channel;
  try {
    const payload: PublishUpdate = { publish_status: 'scheduled', scheduled_at: iso };
    await channelTasksApi.updatePublish(scope.value, channel, payload);
    toast.success('已设置发布排期');
    scheduleInput.value[channel] = '';
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    updatingPublish.value = null;
  }
}

async function cancelSchedule(channel: Channel): Promise<void> {
  updatingPublish.value = channel;
  try {
    const payload: PublishUpdate = { publish_status: 'unpublished' };
    await channelTasksApi.updatePublish(scope.value, channel, payload);
    toast.success('已取消排期');
    scheduleInput.value[channel] = '';
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    updatingPublish.value = null;
  }
}

function askPublish(channel: Channel): void {
  confirm.value = {
    kind: 'publish',
    open: true,
    title: '标记正式发布',
    message:
      publishedAtInput.value[channel] === ''
        ? '确认将该渠道标记为已发布？未填写实际发布时间时将按当前时间记录。已发布是历史事实，普通流程不可回退。'
        : '确认将该渠道标记为已发布？已发布是历史事实，普通流程不可回退。',
    confirmLabel: '标记已发布',
    danger: true,
    channel,
  };
}

async function markPublished(channel: Channel): Promise<void> {
  updatingPublish.value = channel;
  try {
    // DEV-W05.1: a published request must NOT resend scheduled_at — the server keeps the
    // stored value as scheduling history and rejects the field.
    //
    // A non-empty but unparseable value must abort the action. Silently degrading to
    // "no timestamp supplied" would make the server stamp now() and record a publish time
    // the user never chose.
    const raw = publishedAtInput.value[channel];
    let payload: PublishUpdate;
    if (raw === '') {
      payload = { publish_status: 'published' };
    } else {
      const iso = localInputToIso(raw);
      if (iso === null) {
        toast.error('请选择有效的实际发布时间');
        return;
      }
      payload = { publish_status: 'published', published_at: iso };
    }
    await channelTasksApi.updatePublish(scope.value, channel, payload);
    toast.success('已标记为正式发布');
    confirm.value.open = false;
    publishedAtInput.value[channel] = '';
    scheduleInput.value[channel] = '';
    await reload();
  } catch (e) {
    handleActionError(e);
  } finally {
    updatingPublish.value = null;
  }
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
        { label: '制作与渠道' },
      ]"
    />

    <PageHeader
      :title="`制作与渠道 · ${item?.title ?? ''}`"
      description="管理共享图稿、公众号与视频号的制作、审核、排期和发布状态。"
    >
      <template #actions>
        <Link
          :href="itemsUrl"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回篇目
        </Link>
        <Link
          :href="copyUrl"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          编辑文案
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" label="加载制作与渠道工作台…" />
    <ErrorState v-else-if="status === 'error'" :message="loadError" />

    <div v-else class="space-y-6">
      <!-- B. 篇目 / 正式文案概览 -->
      <section class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <h2 class="text-base font-semibold text-slate-900">{{ item?.title }}</h2>
            <Badge
              v-if="item"
              :variant="copyVariant(item.copy_status)"
              :label="COPY_STATUS_LABELS[item.copy_status]"
            />
          </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <div class="rounded-md bg-slate-50 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">当前正式文案</p>
            <p v-if="currentRevision" class="mt-1 text-sm text-slate-700">
              正式文案 Revision {{ currentRevision.revision_no }}
            </p>
            <p v-else class="mt-1 text-sm text-slate-500">尚无正式确认版本</p>
            <p v-if="currentRevision" class="mt-1 text-xs text-slate-400">
              确认时间 {{ fmtLocal(currentRevision.confirmed_at) }}
            </p>
          </div>
          <div class="rounded-md bg-slate-50 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">制作依据</p>
            <p class="mt-1 text-sm text-slate-600">
              渠道制作只能建立在当前正式文案与已验收共享图稿之上。
            </p>
            <p v-if="currentRevision && item && item.copy_status !== 'confirmed'" class="mt-1 text-xs text-amber-700">
              当前存在已确认 Revision，同时有新的工作文案尚未正式确认。
            </p>
          </div>
        </div>
      </section>

      <!-- C/D. 共享图稿 Production 区域 -->
      <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-base font-semibold text-slate-900">共享图稿制作</h2>

        <!-- D. stale 警告 -->
        <div
          v-if="productionStale"
          class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3"
        >
          <p class="text-sm font-medium text-rose-800">正式文案已有新版本</p>
          <p class="mt-1 text-sm text-rose-700">
            当前图稿仍基于 Revision {{ production?.copy_revision_no ?? '—' }}，
            当前正式文案为 Revision {{ currentRevision?.revision_no ?? '—' }}。
            系统不会自动换版，也不会自动重置状态。
          </p>
        </div>

        <!-- 未创建 Production -->
        <div v-if="!production" class="mt-4">
          <EmptyState
            title="共享图稿任务尚未开始"
            description="开始制作后，图稿状态与渠道任务都建立在一份已确认的正式文案之上。"
          >
            <template #action>
              <button
                v-if="item && item.copy_status === 'confirmed' && currentRevision"
                type="button"
                :disabled="creatingProduction"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                @click="createProduction"
              >
                {{ creatingProduction ? '处理中…' : '开始制作' }}
              </button>
              <Link
                v-else
                :href="copyUrl"
                class="inline-block rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                前往编辑文案
              </Link>
            </template>
          </EmptyState>
          <p
            v-if="!item || item.copy_status !== 'confirmed' || !currentRevision"
            class="mt-2 text-center text-sm text-slate-500"
          >
            请先完成正式文案确认，再开始图稿制作。
          </p>
        </div>

        <!-- 已创建 Production -->
        <div v-else class="mt-4 space-y-4">
          <div class="flex flex-wrap items-center gap-3">
            <Badge
              :variant="artworkVariant(production.artwork_status)"
              :label="ARTWORK_STATUS_LABELS[production.artwork_status]"
            />
            <span class="text-sm text-slate-500">
              任务绑定：正式文案 Revision {{ production.copy_revision_no ?? '—' }}
            </span>
            <span class="text-sm text-slate-500">
              当前正式：Revision {{ currentRevision?.revision_no ?? '—' }}
            </span>
            <Badge
              :variant="productionCurrent ? 'active' : 'danger'"
              :label="productionCurrent ? '当前正式文案' : '文案版本已更新'"
            />
          </div>

          <!-- Artwork 状态操作：显式业务动作，不做无脑下拉 -->
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-if="production.artwork_status === 'not_started' || production.artwork_status === 'not_applicable'"
              type="button"
              :disabled="updatingArtwork"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
              @click="setArtwork('in_progress')"
            >
              {{ updatingArtwork ? '处理中…' : '开始制作' }}
            </button>
            <button
              v-if="production.artwork_status === 'in_progress'"
              type="button"
              :disabled="updatingArtwork"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
              @click="setArtwork('pending_review')"
            >
              {{ updatingArtwork ? '处理中…' : '提交审核' }}
            </button>
            <button
              v-if="production.artwork_status === 'pending_review'"
              type="button"
              :disabled="!canApproveArtwork || updatingArtwork"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
              @click="askArtworkApproved"
            >
              审核通过
            </button>
            <button
              v-if="production.artwork_status === 'pending_review'"
              type="button"
              :disabled="updatingArtwork"
              class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              @click="setArtwork('in_progress')"
            >
              退回制作
            </button>
            <span
              v-if="production.artwork_status === 'pending_review' && !canApproveArtwork"
              class="text-xs text-amber-700"
            >
              {{ artworkApproveBlockedReason }}
            </span>
            <span
              v-if="production.artwork_status === 'approved'"
              class="text-sm text-emerald-700"
            >
              图稿已验收，可创建渠道任务。
            </span>
          </div>

          <!-- stale 修复动作 -->
          <div v-if="productionStale" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
            <button
              v-if="canUseCurrentCopy"
              type="button"
              :disabled="switchingCopy"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
              @click="askUseCurrentCopy"
            >
              {{ switchingCopy ? '处理中…' : '切换到最新正式文案' }}
            </button>
            <button
              v-else-if="canRestart"
              type="button"
              :disabled="restartingProduction"
              class="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50"
              @click="askRestart"
            >
              {{ restartingProduction ? '处理中…' : '按最新文案重新开始' }}
            </button>
            <button
              v-else
              type="button"
              disabled
              class="cursor-not-allowed rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-400"
            >
              {{ hasChannelTasks ? '无法重新开始' : '无法切换版本' }}
            </button>
            <p class="text-sm text-slate-500">{{ staleRepairBlockedReason }}</p>
          </div>

          <p class="border-t border-slate-100 pt-3 text-xs text-slate-400">
            视觉资产管理将在后续 Asset 模块接入。
          </p>
        </div>
      </section>

      <!-- 共享视觉资产：per production task, not a standalone global page -->
      <ProductionAssets
        v-if="production"
        :workspace="assetWorkspace"
        :loading="assetLoading"
        :error-message="assetError"
        :saving="assetSaving"
        :registration-disabled="assetRegistrationDisabled"
        :registration-disabled-reason="assetRegistrationDisabledReason"
        @append="appendAssetVersion"
        @open-history="openAssetHistory"
        @reload="loadAssets"
      />

      <!-- E/F/G. 渠道任务区域 -->
      <section>
        <h2 class="mb-3 text-base font-semibold text-slate-900">渠道任务</h2>
        <div class="grid gap-6 lg:grid-cols-2">
          <!-- 微信公众号 -->
          <div class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-sm font-semibold text-slate-900">微信公众号</h3>
              <Badge
                v-if="official"
                :variant="publishVariant(official.publish_status)"
                :label="PUBLISH_STATUS_LABELS[official.publish_status]"
              />
            </div>

            <div v-if="!official" class="mt-4">
              <p class="text-sm text-slate-500">尚未创建渠道任务。</p>
              <button
                type="button"
                :disabled="!canCreateChannel || creatingChannel === 'wechat_official'"
                class="mt-3 w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                @click="createChannel('wechat_official')"
              >
                {{
                  creatingChannel === 'wechat_official'
                    ? '处理中…'
                    : '创建公众号任务'
                }}
              </button>
              <p v-if="!canCreateChannel" class="mt-2 text-xs text-slate-400">
                {{ channelCreateBlockedReason }}
              </p>
            </div>

            <div v-else class="mt-4 space-y-4">
              <div class="space-y-1 text-sm">
                <p class="text-slate-600">
                  视频：{{ VIDEO_STATUS_LABELS[official.video_status] }}
                </p>
                <p class="text-slate-600">
                  发布状态：{{ PUBLISH_STATUS_LABELS[official.publish_status] }}
                </p>
                <p class="text-slate-500">
                  发布排期：{{ fmtLocal(official.scheduled_at) }}
                </p>
                <p class="text-slate-500">
                  实际发布时间：{{ fmtLocal(official.published_at) }}
                </p>
              </div>

              <!-- 公众号没有视频制作阶段，因此不提供 Video 操作 -->
              <p class="text-xs text-slate-400">公众号没有视频制作阶段，无需视频验收。</p>

              <!-- 发布区 -->
              <div class="border-t border-slate-100 pt-4">
                <template v-if="official.publish_status === 'published'">
                  <p class="text-sm font-medium text-emerald-700">已发布</p>
                  <p class="mt-1 text-xs text-slate-400">
                    已发布记录为历史事实，普通工作流不可回退。
                  </p>
                </template>
                <template v-else>
                  <p v-if="!canSchedule(official)" class="mb-2 text-xs text-amber-700">
                    {{ publishBlockedReason(official) }}
                  </p>
                  <div v-if="official.publish_status === 'scheduled'" class="mb-2">
                    <p class="text-xs text-slate-500">
                      当前排期：{{ fmtLocal(official.scheduled_at) }}
                    </p>
                  </div>
                  <label class="block text-xs font-medium text-slate-600">
                    {{ official.publish_status === 'scheduled' ? '新的发布排期' : '发布排期' }}
                  </label>
                  <input
                    v-model="scheduleInput.wechat_official"
                    type="datetime-local"
                    :disabled="!canSchedule(official) || updatingPublish === 'wechat_official'"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none disabled:bg-slate-50"
                  />
                  <div class="mt-2 flex flex-wrap gap-2">
                    <button
                      type="button"
                      :disabled="!canSchedule(official) || updatingPublish === 'wechat_official'"
                      class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                      @click="setSchedule('wechat_official')"
                    >
                      {{
                        official.publish_status === 'scheduled' ? '更新排期' : '设置排期'
                      }}
                    </button>
                    <button
                      v-if="official.publish_status === 'scheduled'"
                      type="button"
                      :disabled="updatingPublish === 'wechat_official'"
                      class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                      @click="cancelSchedule('wechat_official')"
                    >
                      取消排期
                    </button>
                    <button
                      type="button"
                      :disabled="!canPublishNow(official) || updatingPublish === 'wechat_official'"
                      class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                      @click="askPublish('wechat_official')"
                    >
                      标记已发布
                    </button>
                  </div>
                  <label class="mt-3 block text-xs font-medium text-slate-600">
                    实际发布时间（可选，留空按当前时间记录）
                  </label>
                  <input
                    v-model="publishedAtInput.wechat_official"
                    type="datetime-local"
                    :disabled="updatingPublish === 'wechat_official'"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                  />
                </template>
              </div>
            </div>
          </div>

          <!-- 微信视频号 -->
          <div class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-sm font-semibold text-slate-900">微信视频号</h3>
              <Badge
                v-if="videoChannel"
                :variant="publishVariant(videoChannel.publish_status)"
                :label="PUBLISH_STATUS_LABELS[videoChannel.publish_status]"
              />
            </div>

            <div v-if="!videoChannel" class="mt-4">
              <p class="text-sm text-slate-500">尚未创建渠道任务。</p>
              <button
                type="button"
                :disabled="!canCreateChannel || creatingChannel === 'wechat_channels'"
                class="mt-3 w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                @click="createChannel('wechat_channels')"
              >
                {{
                  creatingChannel === 'wechat_channels'
                    ? '处理中…'
                    : '创建视频号任务'
                }}
              </button>
              <p v-if="!canCreateChannel" class="mt-2 text-xs text-slate-400">
                {{ channelCreateBlockedReason }}
              </p>
            </div>

            <div v-else class="mt-4 space-y-4">
              <div class="space-y-1 text-sm">
                <p class="flex items-center gap-2 text-slate-600">
                  视频：
                  <Badge
                    :variant="videoVariant(videoChannel.video_status)"
                    :label="VIDEO_STATUS_LABELS[videoChannel.video_status]"
                  />
                </p>
                <p class="text-slate-600">
                  发布状态：{{ PUBLISH_STATUS_LABELS[videoChannel.publish_status] }}
                </p>
                <p class="text-slate-500">
                  发布排期：{{ fmtLocal(videoChannel.scheduled_at) }}
                </p>
                <p class="text-slate-500">
                  实际发布时间：{{ fmtLocal(videoChannel.published_at) }}
                </p>
              </div>

              <!-- 视频状态操作 -->
              <div class="flex flex-wrap items-center gap-2">
                <button
                  v-if="videoChannel.video_status === 'not_started'"
                  type="button"
                  :disabled="updatingVideo === 'wechat_channels'"
                  class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                  @click="setVideo('wechat_channels', 'in_progress')"
                >
                  开始视频制作
                </button>
                <button
                  v-if="videoChannel.video_status === 'in_progress'"
                  type="button"
                  :disabled="updatingVideo === 'wechat_channels'"
                  class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                  @click="setVideo('wechat_channels', 'pending_review')"
                >
                  提交审核
                </button>
                <button
                  v-if="videoChannel.video_status === 'pending_review'"
                  type="button"
                  :disabled="!canApproveVideo(videoChannel) || updatingVideo === 'wechat_channels'"
                  class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                  @click="askVideoApproved('wechat_channels')"
                >
                  审核通过
                </button>
                <button
                  v-if="videoChannel.video_status === 'pending_review'"
                  type="button"
                  :disabled="updatingVideo === 'wechat_channels'"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  @click="setVideo('wechat_channels', 'in_progress')"
                >
                  退回制作
                </button>
                <span v-if="videoChannel.video_status === 'approved'" class="text-sm text-emerald-700">
                  视频已验收。
                </span>
              </div>
              <p
                v-if="videoChannel.video_status === 'pending_review' && !canApproveVideo(videoChannel)"
                class="text-xs text-amber-700"
              >
                {{ videoApproveBlockedReason(videoChannel) }}
              </p>

              <!-- 发布区 -->
              <div class="border-t border-slate-100 pt-4">
                <template v-if="videoChannel.publish_status === 'published'">
                  <p class="text-sm font-medium text-emerald-700">已发布</p>
                  <p class="mt-1 text-xs text-slate-400">
                    已发布记录为历史事实，普通工作流不可回退。
                  </p>
                </template>
                <template v-else>
                  <p v-if="!canSchedule(videoChannel)" class="mb-2 text-xs text-amber-700">
                    {{ publishBlockedReason(videoChannel) }}
                  </p>
                  <div v-if="videoChannel.publish_status === 'scheduled'" class="mb-2">
                    <p class="text-xs text-slate-500">
                      当前排期：{{ fmtLocal(videoChannel.scheduled_at) }}
                    </p>
                  </div>
                  <label class="block text-xs font-medium text-slate-600">
                    {{
                      videoChannel.publish_status === 'scheduled' ? '新的发布排期' : '发布排期'
                    }}
                  </label>
                  <input
                    v-model="scheduleInput.wechat_channels"
                    type="datetime-local"
                    :disabled="!canSchedule(videoChannel) || updatingPublish === 'wechat_channels'"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none disabled:bg-slate-50"
                  />
                  <div class="mt-2 flex flex-wrap gap-2">
                    <button
                      type="button"
                      :disabled="!canSchedule(videoChannel) || updatingPublish === 'wechat_channels'"
                      class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                      @click="setSchedule('wechat_channels')"
                    >
                      {{
                        videoChannel.publish_status === 'scheduled' ? '更新排期' : '设置排期'
                      }}
                    </button>
                    <button
                      v-if="videoChannel.publish_status === 'scheduled'"
                      type="button"
                      :disabled="updatingPublish === 'wechat_channels'"
                      class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                      @click="cancelSchedule('wechat_channels')"
                    >
                      取消排期
                    </button>
                    <button
                      type="button"
                      :disabled="!canPublishNow(videoChannel) || updatingPublish === 'wechat_channels'"
                      class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                      @click="askPublish('wechat_channels')"
                    >
                      标记已发布
                    </button>
                  </div>
                  <label class="mt-3 block text-xs font-medium text-slate-600">
                    实际发布时间（可选，留空按当前时间记录）
                  </label>
                  <input
                    v-model="publishedAtInput.wechat_channels"
                    type="datetime-local"
                    :disabled="updatingPublish === 'wechat_channels'"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                  />
                </template>
              </div>
            </div>
          </div>
        </div>
        <p class="mt-3 text-xs text-slate-400">{{ timezoneNote }}</p>
      </section>
    </div>

    <!-- Asset version history: strictly read-only (no edit / delete / rollback) -->
    <div
      v-if="assetDetail !== null || assetDetailLoading"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
      @click.self="closeAssetHistory"
    >
      <div class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-start justify-between gap-4">
          <h3 class="text-base font-semibold text-slate-900">资产版本历史</h3>
          <button
            type="button"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
            @click="closeAssetHistory"
          >
            关闭
          </button>
        </div>
        <p class="mt-1 text-xs text-slate-400">历史版本为只读记录，不能编辑、删除或回滚。</p>

        <p v-if="assetDetailLoading" class="mt-4 text-sm text-slate-400">加载版本历史…</p>

        <ul v-else-if="assetDetail" class="mt-4 space-y-3">
          <li
            v-for="version in assetDetail.versions"
            :key="version.id"
            class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3"
          >
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span class="font-medium text-slate-800">v{{ version.version_no }}</span>
              <span class="text-slate-500">
                {{
                  version.copy_revision_no === null
                    ? '未知 Revision'
                    : `Revision ${version.copy_revision_no}`
                }}
              </span>
            </div>
            <p class="mt-1 truncate text-xs text-slate-700">{{ version.file.original_name }}</p>
            <p class="mt-0.5 truncate text-xs text-slate-400">
              {{ version.file.storage_disk }} : {{ version.file.storage_path }}
            </p>
            <p
              v-if="version.file.width !== null && version.file.height !== null"
              class="mt-0.5 text-xs text-slate-400"
            >
              {{ version.file.width }} × {{ version.file.height }}
              <span v-if="formatAssetBytes(version.file.size_bytes)">
                · {{ formatAssetBytes(version.file.size_bytes) }}
              </span>
            </p>
            <p v-if="version.note" class="mt-0.5 text-xs text-slate-500">{{ version.note }}</p>
            <p class="mt-0.5 text-xs text-slate-400">{{ fmtLocal(version.created_at) }}</p>
          </li>
        </ul>

        <p v-if="assetDetail && assetDetail.versions.length === 0" class="mt-4 text-sm text-slate-500">
          暂无版本记录。
        </p>
      </div>
    </div>

    <ConfirmDialog
      :open="confirm.open"
      :title="confirm.title"
      :message="confirm.message"
      :confirm-label="confirm.confirmLabel"
      :danger="confirm.danger"
      :busy="updatingArtwork || switchingCopy || restartingProduction || updatingVideo !== null || updatingPublish !== null"
      @confirm="onConfirm"
      @cancel="confirm.open = false"
    />
  </AdminLayout>
</template>
