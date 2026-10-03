<script setup lang="ts">
import { computed, ref } from 'vue';
import type { AssetRole, Channel, ChannelAssetPageBinding, ChannelAssetWorkspace } from '../api/types';
import { ASSET_ROLE_LABELS, PAGE_TYPE_LABELS } from '../api/types';
import Badge from './Badge.vue';
import ChannelAssetBindingDialog from './ChannelAssetBindingDialog.vue';

/**
 * 渠道素材绑定 — rendered INSIDE an existing channel card.
 *
 * A channel does not own artwork: each page simply points at one shared AssetVersion.
 * The expected role (copy_master for 公众号, clean_master for 视频号) is decided by the
 * server and only surfaced here as a label; the user cannot change it.
 */
const props = defineProps<{
  channel: Channel;
  workspace: ChannelAssetWorkspace | null;
  loading: boolean;
  errorMessage: string;
  saving: boolean;
}>();

const emit = defineEmits<{
  bind: [payload: { content_page_id: number; asset_version_id: number }];
}>();

const dialogOpen = ref(false);
const dialogPage = ref<ChannelAssetPageBinding | null>(null);

const role = computed<AssetRole | null>(() => props.workspace?.expected_asset_role ?? null);
const roleLabel = computed(() => (role.value ? ASSET_ROLE_LABELS[role.value] : ''));

const remaining = computed(() => {
  if (!props.workspace) return 0;
  return Math.max(props.workspace.total_page_count - props.workspace.bound_page_count, 0);
});

function pageLabel(page: ChannelAssetPageBinding): string {
  const label = PAGE_TYPE_LABELS[page.page_type] ?? page.page_type;
  return `第 ${page.page_no} 页 · ${label}`;
}

function openBind(page: ChannelAssetPageBinding): void {
  dialogPage.value = page;
  dialogOpen.value = true;
}

function closeDialog(): void {
  dialogOpen.value = false;
  dialogPage.value = null;
}

function submitBind(assetVersionId: number): void {
  if (dialogPage.value === null) return;
  emit('bind', {
    content_page_id: dialogPage.value.content_page_id,
    asset_version_id: assetVersionId,
  });
  // The parent closes the dialog only after a successful POST, so a 422 keeps the
  // user's selection and the page state intact.
}

/**
 * Parent calls this ONLY after a successful bind. Closing on failure would discard the
 * user's selected version, so a 422 keeps the dialog open.
 */
defineExpose({ closeBindDialog: closeDialog });

/** Nudge for the channel-specific role when the page has no bindable version yet. */
function emptyHint(): string {
  return role.value === 'copy_master'
    ? '请先在上方共享视觉资产中登记 copy_master。'
    : '请先登记 clean_master。';
}
</script>

<template>
  <div class="mt-4 border-t border-slate-100 pt-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-semibold text-slate-900">渠道素材绑定</h4>
      <div class="flex items-center gap-2">
        <span class="text-xs text-slate-500">
          素材来源：{{ roleLabel || '—' }}
          <span v-if="role" class="text-slate-400">（{{ role }}）</span>
        </span>
        <Badge
          v-if="workspace"
          :variant="workspace.is_complete ? 'active' : 'planning'"
          :label="
            workspace.is_complete
              ? '全部页面已绑定'
              : `还有 ${remaining} 页未绑定`
          "
        />
      </div>
    </div>

    <p v-if="workspace" class="mt-1 text-xs text-slate-500">
      已绑定 {{ workspace.bound_page_count }} / {{ workspace.total_page_count }} 页
    </p>

    <!-- stale production: binding stays allowed, but be explicit about what it targets -->
    <p
      v-if="workspace && !workspace.is_production_copy_current"
      class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800"
    >
      当前渠道任务仍绑定旧正式文案 Revision
      {{ workspace.copy_revision_no ?? '—' }}；本次素材绑定也只属于该旧 Revision。
    </p>

    <p v-if="loading" class="mt-3 text-sm text-slate-400">加载渠道素材绑定…</p>
    <p v-else-if="errorMessage" class="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
      {{ errorMessage }}
    </p>

    <div v-else-if="workspace && workspace.pages.length === 0" class="mt-3">
      <p class="text-sm text-slate-500">当前制作 Revision 还没有页面。</p>
    </div>

    <div v-else-if="workspace" class="mt-3 space-y-3">
      <div
        v-for="page in workspace.pages"
        :key="page.content_page_id"
        class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm font-medium text-slate-800">{{ pageLabel(page) }}</span>
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="page.available_versions.length === 0"
            @click="openBind(page)"
          >
            绑定此版本
          </button>
        </div>

        <!-- current binding: only what the pinned revision actually uses -->
        <div v-if="page.current_binding" class="mt-1 space-y-0.5 text-xs">
          <p class="text-slate-700">
            Revision {{ page.current_binding.copy_revision_no ?? '—' }} · Asset v{{
              page.current_binding.asset_version.version_no
            }}
          </p>
          <p class="truncate text-slate-600">{{ page.current_binding.asset_version.file.original_name }}</p>
          <p class="truncate text-slate-400">
            {{ page.current_binding.asset_version.file.storage_disk }} :
            {{ page.current_binding.asset_version.file.storage_path }}
          </p>
        </div>
        <p v-else class="mt-1 text-xs text-slate-500">当前 Revision 尚未绑定</p>

        <!-- history pointer: never promoted to "current" automatically -->
        <p
          v-if="
            page.latest_binding !== null &&
            (page.current_binding === null ||
              page.latest_binding.id !== page.current_binding.id)
          "
          class="mt-1 text-xs text-slate-500"
        >
          历史最新：Revision {{ page.latest_binding.copy_revision_no ?? '—' }} · Binding #{{
            page.latest_binding.binding_no
          }}
        </p>

        <!-- no bindable version: point at the shared asset section, never auto-create -->
        <p v-if="page.available_versions.length === 0" class="mt-1 text-xs text-amber-700">
          当前 Revision 尚没有可用于本渠道的共享视觉资产。{{ emptyHint() }}
        </p>
      </div>
    </div>

    <p class="mt-3 text-xs text-slate-400">
      渠道只引用共享视觉资产，不复制图片、不生成渠道专属图片，也没有上传与预览。
    </p>

    <ChannelAssetBindingDialog
      :open="dialogOpen"
      :page-label="dialogPage ? pageLabel(dialogPage) : ''"
      :role-label="roleLabel"
      :versions="dialogPage ? dialogPage.available_versions : []"
      :saving="saving"
      @submit="submitBind"
      @cancel="closeDialog"
    />
  </div>
</template>
