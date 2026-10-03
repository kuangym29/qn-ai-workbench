<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { AssetRole, AssetVersionAppendInput } from '../api/types';
import { ASSET_ROLE_LABELS } from '../api/types';

const props = defineProps<{
  open: boolean;
  /** Slot the user clicked; null when disabled. */
  contentPageId: number | null;
  pageLabel: string;
  role: AssetRole | null;
  saving: boolean;
}>();

const emit = defineEmits<{
  submit: [payload: AssetVersionAppendInput];
  cancel: [];
}>();

const storageDisk = ref('local');
const storagePath = ref('');
const originalName = ref('');
const mimeType = ref('');
const sizeBytes = ref('');
const width = ref('');
const height = ref('');
const note = ref('');
const error = ref('');

// Reset the form each time the dialog opens for a different slot.
watch(
  () => [props.open, props.contentPageId, props.role],
  () => {
    if (!props.open) return;
    storageDisk.value = 'local';
    storagePath.value = '';
    originalName.value = '';
    mimeType.value = '';
    sizeBytes.value = '';
    width.value = '';
    height.value = '';
    note.value = '';
    error.value = '';
  },
);

/**
 * Client-side mirror of the server's storage_path rules. The server stays the final gate;
 * this only avoids a pointless round trip. The normal form is `/` separated and relative
 * to the brand source root.
 */
function canonicalPath(value: string): string | null {
  let path = value.trim().replace(/\\/g, '/').replace(/\/{2,}/g, '/');
  if (path.startsWith('//')) return null; // UNC
  if (path.startsWith('/')) return null; // absolute
  if (/^[A-Za-z]:\//.test(path)) return null; // windows absolute
  while (path.startsWith('./')) path = path.slice(2);
  path = path.replace(/^\/+|\/+$/g, '');
  if (path === '') return null;
  if (path.split('/').some((segment) => segment === '..')) return null;
  if (path.split('/').every((segment) => segment === '.')) return null;
  return path;
}

function optionalInt(value: string): number | null {
  const trimmed = value.trim();
  if (trimmed === '') return null;
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : null;
}

const canSubmit = computed(() => props.contentPageId !== null && props.role !== null);

function submit(): void {
  if (!canSubmit.value || props.contentPageId === null || props.role === null) {
    error.value = '请先选择一个资产槽位';
    return;
  }

  const disk = storageDisk.value.trim();
  if (disk === '') {
    error.value = '请填写存储盘';
    return;
  }

  const path = canonicalPath(storagePath.value);
  if (path === null) {
    error.value = '请填写有效的相对存储路径';
    return;
  }

  const name = originalName.value.trim();
  if (name === '' || name === '.' || name === '..' || name.includes('/') || name.includes('\\')) {
    error.value = '请填写有效的原始文件名（不含路径分隔符）';
    return;
  }

  error.value = '';
  emit('submit', {
    content_page_id: props.contentPageId,
    role: props.role,
    storage_disk: disk,
    storage_path: path,
    original_name: name,
    mime_type: mimeType.value.trim() === '' ? null : mimeType.value.trim(),
    size_bytes: optionalInt(sizeBytes.value),
    width: optionalInt(width.value),
    height: optionalInt(height.value),
    note: note.value.trim() === '' ? null : note.value.trim(),
  });
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
    @click.self="emit('cancel')"
  >
    <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
      <h3 class="text-base font-semibold text-slate-900">登记资产版本</h3>
      <p class="mt-1 text-sm text-slate-600">
        槽位：{{ pageLabel }} · {{ role ? ASSET_ROLE_LABELS[role] : '' }}
      </p>

      <!-- This is a locator form, not an uploader. -->
      <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
        当前只登记文件定位和版本信息，不会上传或读取文件。
      </p>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-slate-700">存储盘</label>
            <input
              v-model="storageDisk"
              type="text"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">原始文件名</label>
            <input
              v-model="originalName"
              type="text"
              placeholder="例如 page-01-clean.png"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700">
            存储路径 <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="storagePath"
            type="text"
            placeholder="例如 2026/10/page-01-clean.png"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          />
          <p class="mt-1 text-xs text-slate-400">相对路径，使用 / 分隔。</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-slate-700">MIME（可选）</label>
            <input
              v-model="mimeType"
              type="text"
              placeholder="image/png"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">文件大小 bytes（可选）</label>
            <input
              v-model="sizeBytes"
              type="number"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            />
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-slate-700">宽（可选）</label>
            <input
              v-model="width"
              type="number"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">高（可选）</label>
            <input
              v-model="height"
              type="number"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700">备注（可选）</label>
          <input
            v-model="note"
            type="text"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          />
        </div>

        <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>

        <div class="flex items-center justify-end gap-3">
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
            :disabled="saving"
            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
          >
            {{ saving ? '登记中…' : '登记版本' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
