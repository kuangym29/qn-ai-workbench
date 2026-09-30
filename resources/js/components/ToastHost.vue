<script setup lang="ts">
import { toasts } from '../ui/toast';
</script>

<template>
  <div class="pointer-events-none fixed right-4 top-4 z-50 flex flex-col gap-2">
    <div
      v-for="t in toasts"
      :key="t.id"
      class="pointer-events-auto rounded-md px-4 py-2 text-sm font-medium text-white shadow-lg"
      :class="{
        'bg-emerald-600': t.type === 'success',
        'bg-rose-600': t.type === 'error',
        'bg-slate-700': t.type === 'info',
      }"
    >
      {{ t.message }}
    </div>
  </div>
</template>
