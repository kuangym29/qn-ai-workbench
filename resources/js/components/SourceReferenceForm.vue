<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { SourceReference, SourceReferenceInput, SourceRole } from '../api/types';
import { SOURCE_ROLE_LABELS } from '../api/types';

const props = defineProps<{
  /** null = create mode, otherwise the record being edited. */
  reference: SourceReference | null;
  /** Roles allowed at this scope (project-scoped or item-scoped). */
  roles: SourceRole[];
  saving: boolean;
}>();

const emit = defineEmits<{
  submit: [payload: SourceReferenceInput];
  cancel: [];
}>();

const role = ref<SourceRole>(props.roles[0] ?? 'content_ledger');
const sourcePath = ref('');
const note = ref('');
const error = ref('');

// Reset the form whenever the dialog target changes (create → edit).
watch(
  () => props.reference,
  (next) => {
    role.value = next?.role ?? props.roles[0] ?? 'content_ledger';
    sourcePath.value = next?.source_path ?? '';
    note.value = next?.note ?? '';
    error.value = '';
  },
  { immediate: true },
);

const editing = computed(() => props.reference !== null);

function submit(): void {
  const path = sourcePath.value.trim();
  if (path === '') {
    error.value = '请填写来源路径';
    return;
  }
  // Client-side mirror of the server's relative-path rule; the server stays the gate.
  const normalised = path.replace(/\\/g, '/');
  if (normalised.startsWith('/') || /^[A-Za-z]:\//.test(normalised) || normalised.startsWith('//')) {
    error.value = '请填写相对路径，不能是绝对路径';
    return;
  }
  if (normalised.split('/').some((segment) => segment === '..')) {
    error.value = '路径不能包含 .. ';
    return;
  }

  error.value = '';
  emit('submit', {
    role: role.value,
    source_path: normalised,
    note: note.value.trim() === '' ? null : note.value.trim(),
  });
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <div>
      <label class="block text-sm font-medium text-slate-700">来源角色</label>
      <select
        v-model="role"
        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
      >
        <option v-for="value in roles" :key="value" :value="value">
          {{ SOURCE_ROLE_LABELS[value] }}
        </option>
      </select>
      <p class="mt-1 text-xs text-slate-400">
        角色决定来源性质；权威程度由系统按角色自动判定，无需手动选择。
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700">
        相对路径 <span class="text-rose-500">*</span>
      </label>
      <input
        v-model="sourcePath"
        type="text"
        placeholder="例如 01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md"
        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
      />
      <p class="mt-1 text-xs text-slate-400">
        只记录品牌源根目录下的相对路径，不上传文件，也不检查文件是否存在。
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700">备注</label>
      <textarea
        v-model="note"
        rows="2"
        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
      ></textarea>
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
        {{ saving ? '保存中…' : editing ? '保存修改' : '新增来源' }}
      </button>
    </div>
  </form>
</template>
