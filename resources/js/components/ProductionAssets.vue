<script setup lang="ts">
import { computed, ref } from 'vue';
import type {
  AssetRole,
  AssetSlot,
  AssetVersion,
  AssetVersionAppendInput,
  AssetWorkspace,
} from '../api/types';
import { ASSET_ROLE_LABELS, ASSET_ROLE_ORDER, PAGE_TYPE_LABELS } from '../api/types';
import Badge from './Badge.vue';
import AssetVersionDialog from './AssetVersionDialog.vue';

/**
 * 共享视觉资产 section of the Production workspace.
 *
 * Renders the API's pinned-revision page matrix verbatim (never re-derived from live
 * ContentPage rows), shows current vs latest version per slot, and lets the user register
 * a new version. Registration is a *locator* form: no upload, no preview, no channel
 * binding — see AssetVersionDialog.
 *
 * The parent owns all data loading and error routing; this component is presentational
 * apart from the two dialogs it owns.
 */
const props = defineProps<{
  workspace: AssetWorkspace | null;
  loading: boolean;
  errorMessage: string;
  saving: boolean;
  /** True when the production task has no pinned copy revision (legacy rows). */
  registrationDisabled: boolean;
  registrationDisabledReason: string;
}>();

const emit = defineEmits<{
  reload: [];
  append: [payload: AssetVersionAppendInput];
  openHistory: [assetId: number];
}>();

const dialogOpen = ref(false);
const dialogPageId = ref<number | null>(null);
const dialogPageLabel = ref('');
const dialogRole = ref<AssetRole | null>(null);

function openAppend(pageId: number, pageLabel: string, role: AssetRole): void {
  dialogPageId.value = pageId;
  dialogPageLabel.value = pageLabel;
  dialogRole.value = role;
  dialogOpen.value = true;
}

function closeDialog(): void {
  dialogOpen.value = false;
  dialogPageId.value = null;
  dialogRole.value = null;
}

function submitAppend(payload: AssetVersionAppendInput): void {
  emit('append', payload);
}

function closeOnCancel(): void {
  closeDialog();
}

/**
 * Parent calls this ONLY after a successful append. Closing on failure would discard
 * the user's input, so the parent deliberately keeps the dialog open on 422.
 */
defineExpose({ closeAppendDialog: closeDialog });

// ---- presentation helpers ----------------------------------------------

function pageLabel(pageNo: number, pageType: string): string {
  const label = PAGE_TYPE_LABELS[pageType as keyof typeof PAGE_TYPE_LABELS] ?? pageType;
  return `第 ${pageNo} 页 · ${label}`;
}

/** Human readable byte size; purely cosmetic. */
function formatBytes(size: number | null): string {
  if (size === null || !Number.isFinite(size)) return '';
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function versionTitle(version: AssetVersion): string {
  return `v${version.version_no}`;
}

function versionRevision(version: AssetVersion): string {
  return version.copy_revision_no === null ? '未知 Revision' : `Revision ${version.copy_revision_no}`;
}

/** True when the slot's newest version belongs to an older revision than "current". */
function hasHistoricalOnly(slot: AssetSlot): boolean {
  return (
    slot.latest_version !== null &&
    (slot.current_version === null || slot.latest_version.id !== slot.current_version.id)
  );
}
</script>

<template>
  <section class="rounded-lg border border-slate-200 bg-white p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-base font-semibold text-slate-900">共享视觉资产</h2>
      <div class="flex items-center gap-2">
        <span v-if="workspace" class="text-sm text-slate-500">
          当前制作 Revision {{ workspace.copy_revision_no ?? '—' }}
        </span>
        <Badge
          v-if="workspace"
          :variant="workspace.is_copy_revision_current ? 'active' : 'danger'"
          :label="workspace.is_copy_revision_current ? '当前正式版本' : '制作任务绑定旧 Revision'"
        />
      </div>
    </div>
    <p class="mt-1 text-sm text-slate-500">
      每一页共享一套视觉母版：公众号使用有文案定稿图，视频号使用无文案底图。资产版本与正式文案
      Revision 分开保留，不覆盖历史。
    </p>

    <!-- Legacy production task without a pinned revision -->
    <p v-if="registrationDisabled" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
      {{ registrationDisabledReason }}
    </p>

    <!-- Stale production: still allowed to register, but say what it means -->
    <p
      v-else-if="workspace && !workspace.is_copy_revision_current"
      class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
    >
      本次登记会归入制作任务当前绑定的 Revision {{ workspace.copy_revision_no ?? '—' }}；新的正式文案不会自动套用旧图稿。
    </p>

    <!-- Loading / error -->
    <p v-if="loading" class="mt-4 text-sm text-slate-400">加载视觉资产…</p>
    <p v-else-if="errorMessage" class="mt-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
      {{ errorMessage }}
    </p>

    <div v-else-if="workspace && workspace.pages.length === 0" class="mt-4">
      <p class="text-sm text-slate-500">当前制作 Revision 还没有页面，无法登记视觉资产。</p>
    </div>

    <!-- Page matrix: rendered in API order (pinned snapshot) -->
    <div v-else-if="workspace" class="mt-4 space-y-5">
      <div v-for="page in workspace.pages" :key="page.content_page_id">
        <h3 class="text-sm font-semibold text-slate-800">
          {{ pageLabel(page.page_no, page.page_type) }}
        </h3>
        <div class="mt-2 grid gap-3 lg:grid-cols-2">
          <div
            v-for="role in ASSET_ROLE_ORDER"
            :key="role"
            class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs font-medium uppercase tracking-wide text-slate-500">
                {{ ASSET_ROLE_LABELS[role] }}
              </span>
              <button
                type="button"
                class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="registrationDisabled"
                @click="openAppend(page.content_page_id, pageLabel(page.page_no, page.page_type), role)"
              >
                登记版本
              </button>
            </div>

            <!-- No slot yet: the first POST creates it server-side -->
            <template v-if="!page.assets[role]">
              <p class="mt-2 text-sm text-slate-500">未登记</p>
            </template>

            <template v-else>
              <!-- Current version for the pinned revision -->
              <div v-if="page.assets[role].current_version" class="mt-2 space-y-1 text-sm">
                <p class="text-slate-700">
                  {{ versionTitle(page.assets[role].current_version!) }}
                  <span class="text-slate-500">
                    · {{ versionRevision(page.assets[role].current_version!) }}
                  </span>
                </p>
                <p class="truncate text-xs text-slate-600">
                  {{ page.assets[role].current_version!.file.original_name }}
                </p>
                <p class="truncate text-xs text-slate-400">
                  {{ page.assets[role].current_version!.file.storage_disk }} :
                  {{ page.assets[role].current_version!.file.storage_path }}
                </p>
                <p
                  v-if="
                    page.assets[role].current_version!.file.width !== null &&
                    page.assets[role].current_version!.file.height !== null
                  "
                  class="text-xs text-slate-400"
                >
                  {{ page.assets[role].current_version!.file.width }} ×
                  {{ page.assets[role].current_version!.file.height }}
                  <span v-if="formatBytes(page.assets[role].current_version!.file.size_bytes)">
                    · {{ formatBytes(page.assets[role].current_version!.file.size_bytes) }}
                  </span>
                </p>
                <p
                  v-if="page.assets[role].current_version!.note"
                  class="text-xs text-slate-500"
                >
                  {{ page.assets[role].current_version!.note }}
                </p>
              </div>
              <!-- Slot exists but the pinned revision has no version yet -->
              <p v-else class="mt-2 text-sm text-slate-500">当前 Revision 尚无版本</p>

              <!-- History pointer: never present it as the current version -->
              <p v-if="hasHistoricalOnly(page.assets[role]!)" class="mt-2 text-xs text-slate-500">
                历史最新：{{ versionRevision(page.assets[role]!.latest_version!) }} ·
                {{ versionTitle(page.assets[role]!.latest_version!) }}
              </p>

              <div class="mt-2">
                <button
                  type="button"
                  class="text-xs font-medium text-slate-600 underline hover:text-slate-900"
                  @click="emit('openHistory', page.assets[role]!.id)"
                >
                  查看历史（{{ page.assets[role]!.version_count }}）
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <p class="mt-4 text-xs text-slate-400">
      资产只登记文件定位信息：本页不上传、不预览文件，也不绑定渠道。
    </p>

    <!-- Register a version: locator form only -->
    <AssetVersionDialog
      :open="dialogOpen"
      :content-page-id="dialogPageId"
      :page-label="dialogPageLabel"
      :role="dialogRole"
      :saving="saving"
      @submit="submitAppend"
      @cancel="closeOnCancel"
    />
  </section>
</template>
