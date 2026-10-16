<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { v2Api, type InProgressItem } from '../../api/v2';
import { firstErrorMessage } from '../../api/errors';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
const props = defineProps<{ projectId: string | number }>();
const items = ref<InProgressItem[]>([]); const loading = ref(true); const error = ref('');
const channels = ['wechat_official', 'wechat_channels', 'xiaohongshu'];
function summary(item: InProgressItem, channel: string) { return item.active_branches.filter(b => b.channel === channel).map(b => b.format_label).join('、') || '—'; }
onMounted(async () => { try { items.value = await v2Api.progress(props.projectId); } catch(e) { error.value = firstErrorMessage(e) ?? '加载失败'; } finally { loading.value = false; } });
</script>
<template><AdminLayout><PageHeader title="进行中" description="查看每份内容的制作渠道与下一步。" /><p v-if="error" role="alert" class="mb-4 text-red-700">{{ error }}</p><p v-if="loading" role="status">正在加载…</p><p v-else-if="!error && !items.length" class="text-slate-500">还没有创作中的内容，去选题中开始创作。</p><div v-else-if="items.length" class="overflow-x-auto rounded-lg border border-slate-200 bg-white"><table class="w-full text-left text-sm"><caption class="sr-only">内容制作进展</caption><thead class="bg-slate-50 text-slate-500"><tr><th scope="col" class="p-4">内容</th><th scope="col" class="p-4">公众号</th><th scope="col" class="p-4">视频号</th><th scope="col" class="p-4">小红书</th><th scope="col" class="p-4">下一步</th></tr></thead><tbody><tr v-for="item in items" :key="item.id" class="border-t border-slate-100"><th scope="row" class="p-4 font-medium"><Link :href="`/projects/${projectId}/content/${item.id}`" class="underline">{{ item.title }}</Link></th><td v-for="channel in channels" :key="channel" class="p-4 text-slate-600">{{ summary(item, channel) }}</td><td class="p-4"><Link :href="`/projects/${projectId}/content/${item.id}`" class="whitespace-nowrap underline">{{ item.next_action }}</Link></td></tr></tbody></table></div></AdminLayout></template>
