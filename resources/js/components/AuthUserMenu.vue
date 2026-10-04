<script setup lang="ts">
import { computed, ref } from 'vue';
import type { AuthUser } from '../api/auth';

// Displays the server-verified user provided by AdminLayout and emits logout.
// It never fetches, persists, or invents an identity of its own.
const props = defineProps<{
  user: AuthUser;
}>();

const emit = defineEmits<{
  (e: 'logout'): void;
}>();

const open = ref(false);

// Only the first character of the name is shown, so a long Chinese name cannot push
// the topbar layout around.
const initial = computed(() => props.user.name.trim().charAt(0) || props.user.email.charAt(0));

function toggle(): void {
  open.value = !open.value;
}

function requestLogout(): void {
  open.value = false;
  emit('logout');
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="flex items-center gap-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
      :aria-expanded="open"
      @click="toggle"
    >
      <span
        class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700"
      >
        {{ initial }}
      </span>
      <span class="max-w-[10rem] truncate">{{ user.name }}</span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 z-40 mt-1 w-60 rounded-md border border-slate-200 bg-white py-1 shadow-lg"
    >
      <div class="border-b border-slate-100 px-3 py-2">
        <p class="truncate text-sm font-medium text-slate-900">{{ user.name }}</p>
        <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
      </div>
      <button
        type="button"
        class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
        @click="requestLogout"
      >
        退出登录
      </button>
    </div>
  </div>
</template>
