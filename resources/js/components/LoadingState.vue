<script setup lang="ts">
defineProps<{ label?: string }>();
</script>

<template>
  <div
    class="flex items-center justify-center gap-3 rounded-lg border border-slate-200 bg-white px-6 py-12"
  >
    <svg
      class="h-5 w-5 animate-spin text-slate-400"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"
      />
    </svg>
    <span class="text-sm text-slate-500">{{ label ?? '加载中…' }}</span>
  </div>
</template>
