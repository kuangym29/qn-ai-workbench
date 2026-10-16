<script setup lang="ts">
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
defineProps<{ projectId: string | number }>();
</script>
<template><AdminLayout><PageHeader title="待我审核" description="集中处理需要你确认的内容。" /><div class="rounded-lg border border-slate-200 bg-white p-6"><p class="font-medium">审核功能正在准备中</p><p class="mt-2 text-sm text-slate-500">后续将在这里汇总文案、图片和视频的待确认事项。</p></div></AdminLayout></template>
