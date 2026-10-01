<script setup lang="ts">
defineProps<{ title?: string; message?: string }>();
</script>

<template>
  <div
    class="flex flex-col items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-6 py-12 text-center"
  >
    <div
      class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-rose-100 text-rose-500"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
        class="h-6 w-6"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
        />
      </svg>
    </div>
    <p class="text-sm font-medium text-rose-700">{{ title ?? '出错了' }}</p>
    <p v-if="message" class="mt-1 max-w-sm text-sm text-rose-600">{{ message }}</p>
    <div v-if="$slots.action" class="mt-4">
      <slot name="action" />
    </div>
  </div>
</template>
