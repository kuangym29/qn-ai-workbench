<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { AssetVersion } from '../api/types';

const props = defineProps<{
  open: boolean;
  pageLabel: string;
  roleLabel: string;
  /** Already filtered by the backend; the UI applies no extra rules. */
  versions: AssetVersion[];
  saving: boolean;
}>();

const emit = defineEmits<{
  submit: [assetVersionId: number];
  cancel: [];
}>();

const selectedId = ref<number | null>(null);

watch(
  () => [props.open, props.versions],
  () => {
    if (props.open) selectedId.value = null;
  },
);

const canSubmit = computed(() => selectedId.value !== null && !props.saving);

function versionSubtitle(version: AssetVersion): string {
  const revision =
    version.copy_revision_no === null ? '未知 Revision' : `Revision ${version.copy_revision_no}`;
  return `${revision} · ${version.file.storage_disk} : ${version.file.storage_path}`;
}

function submit(): void {
  if (selectedId.value === null) return;
  emit('submit', selectedId.value);
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
    @click.self="emit('cancel')"
  >
    <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
      <h3 class="text-base font-semibold text-slate-900">绑定渠道素材</h3>
      <p class="mt-1 text-sm text-slate-600">
        {{ pageLabel }} · {{ roleLabel }}
      </p>
      <p class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
        渠道只引用共享视觉资产，不复制图片；以下版本来自制作任务当前绑定的正式文案 Revision。
      </p>

      <form class="mt-4 space-y-3" @submit.prevent="submit">
        <div
          v-for="version in versions"
          :key="version.id"
          class="rounded-md border px-3 py-2"
          :class="
            selectedId === version.id ? 'border-slate-900 bg-slate-50' : 'border-slate-200 bg-white'
          "
        >
          <label class="flex cursor-pointer items-start gap-2">
            <input v-model="selectedId" type="radio" name="asset-version" :value="version.id" class="mt-1" />
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-slate-800">
                v{{ version.version_no }} · {{ version.file.original_name }}
              </span>
              <span class="block truncate text-xs text-slate-400">{{ versionSubtitle(version) }}</span>
            </span>
          </label>
        </div>

        <p v-if="versions.length === 0" class="text-sm text-slate-500">
          当前 Revision 尚没有可用于本渠道的共享视觉资产。
        </p>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            :disabled="saving"
            @click="emit('cancel')"
          >
            取消
          </button>
          <button
            type="submit"
            :disabled="!canSubmit"
            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
          >
            {{ saving ? '绑定中…' : '绑定此版本' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
