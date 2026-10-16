<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { v2Api, type ContentItem } from '../../api/v2';
import { firstErrorMessage } from '../../api/errors';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import SourceTrail from '../../components/SourceTrail.vue';
const props = defineProps<{ projectId: string | number }>();
const items = ref<ContentItem[]>([]); const loading = ref(true); const error = ref('');
onMounted(async () => { try { items.value = await v2Api.items(props.projectId); } catch(e) { error.value = firstErrorMessage(e) ?? '加载失败'; } finally { loading.value = false; } });
</script>
<template><AdminLayout><PageHeader title="内容库" description="每个选题保存一份共享内容，关联多个制作渠道。" /><p v-if="error" role="alert" class="text-red-700">{{ error }}</p><p v-if="loading" role="status">正在加载…</p><p v-else-if="!error && !items.length" class="text-slate-500">还没有内容，去选题中开始创作。</p><article v-for="item in items" :key="item.id" class="mb-3 rounded-lg border border-slate-200 bg-white p-4"><Link :href="`/projects/${projectId}/content/${item.id}`" class="font-semibold underline">{{ item.title }}</Link><p class="mt-2 text-sm text-slate-600">{{ item.core_message || '待完善核心表达' }}</p><SourceTrail :inspiration="item.topic?.inspiration" :topic="item.topic" /><div class="mt-3 flex flex-wrap gap-2"><span v-for="b in item.content_branches" :key="b.id" class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ b.channel_label }} · {{ b.format_label }}{{ b.stopped_at ? '（已停止）' : '' }}</span></div></article></AdminLayout></template>
