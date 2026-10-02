<script setup lang="ts">
// Minimal, dependency-free confirmation overlay.
//
// The project has no modal component yet, and this round must not pull in a third-party
// UI kit, so this is a deliberately small, generic confirm dialog used for the
// irreversible actions in the Production workspace (artwork approval, video approval,
// use-current-copy and restart-with-current-copy).

defineProps<{
  open: boolean;
  title: string;
  /** Body copy. Plain text only — keep the wording business-facing. */
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Renders the confirm button in red for destructive resets. */
  danger?: boolean;
  busy?: boolean;
}>();

const emit = defineEmits<{
  confirm: [];
  cancel: [];
}>();
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
    @click.self="emit('cancel')"
  >
    <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
      <h3 class="text-base font-semibold text-slate-900">{{ title }}</h3>
      <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ message }}</p>
      <slot />
      <div class="mt-5 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          :disabled="busy"
          @click="emit('cancel')"
        >
          {{ cancelLabel ?? '取消' }}
        </button>
        <button
          type="button"
          class="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
          :class="danger ? 'bg-rose-700 hover:bg-rose-800' : 'bg-slate-900 hover:bg-slate-800'"
          :disabled="busy"
          @click="emit('confirm')"
        >
          {{ busy ? '处理中…' : (confirmLabel ?? '确认') }}
        </button>
      </div>
    </div>
  </div>
</template>
