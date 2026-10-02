<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// Lightweight hierarchy breadcrumb: 项目 / 栏目 / 选题 / 篇目.
// It only renders context the page already has — it never triggers a new backend endpoint.
defineProps<{ items: { label: string; href?: string }[] }>();
</script>

<template>
  <nav class="mb-3 flex flex-wrap items-center gap-1 text-xs text-slate-500">
    <template v-for="(item, index) in items" :key="index">
      <span v-if="index > 0" class="text-slate-300">/</span>
      <Link
        v-if="item.href"
        :href="item.href"
        class="rounded px-1 hover:text-slate-800 hover:underline"
      >
        {{ item.label }}
      </Link>
      <span v-else class="px-1 font-medium text-slate-700">{{ item.label }}</span>
    </template>
  </nav>
</template>
