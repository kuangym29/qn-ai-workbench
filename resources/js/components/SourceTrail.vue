<script setup lang="ts">
defineProps<{ inspiration?: {id: number; body: string} | null; topic?: {id: number; title: string} | null }>();
</script>
<template><details class="mt-2 text-sm text-slate-500"><summary class="cursor-pointer">来源 › {{ inspiration ? '来自灵感' : '直接创建选题' }}</summary><p v-if="inspiration" class="mt-2 whitespace-pre-wrap">灵感：{{ inspiration.body }}</p><p v-if="topic" class="mt-2">选题：{{ topic.title }}</p></details></template>
