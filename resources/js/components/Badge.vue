<script setup lang="ts">
// 'danger' marks blocked / stale / terminal-conflict states (DEV-W06).
type Variant = 'default' | 'muted' | 'active' | 'planning' | 'danger';

defineProps<{ variant?: Variant; label?: string }>();
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
    :class="{
      'bg-slate-100 text-slate-600': (variant ?? 'default') === 'default',
      'bg-slate-50 text-slate-400': variant === 'muted',
      'bg-emerald-100 text-emerald-700': variant === 'active',
      'bg-amber-100 text-amber-700': variant === 'planning',
      'bg-rose-100 text-rose-700': variant === 'danger',
    }"
  >
    <slot>{{ label }}</slot>
  </span>
</template>
