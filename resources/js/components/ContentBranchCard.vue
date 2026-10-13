<script setup lang="ts">
import type { ContentBranch } from '../api/v2';
import { Link } from '@inertiajs/vue3';
defineProps<{ branch: ContentBranch; busy?: boolean; editorHref?: string }>();
const emit = defineEmits<{ stop: []; resume: [] }>();
</script>
<template><article class="rounded-lg border p-4" :class="branch.stopped_at ? 'border-slate-200 bg-slate-50 text-slate-500' : 'border-slate-200 bg-white'"><h3 class="font-medium">{{ branch.channel_label }} · {{ branch.format_label }}</h3><p class="my-2 text-sm text-slate-500">{{ branch.stopped_at ? '已停止，历史记录保留' : '待开始制作' }}</p><Link v-if="editorHref && !branch.stopped_at" :href="editorHref" class="mr-4 text-sm underline">编辑图片文章</Link><button v-if="branch.stopped_at" type="button" class="text-sm underline" :disabled="busy" @click="emit('resume')">恢复</button><button v-else type="button" class="text-sm text-slate-500 underline" :disabled="busy" @click="emit('stop')">停止制作</button></article></template>
