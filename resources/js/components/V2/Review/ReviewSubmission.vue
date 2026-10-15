<script setup lang="ts">
import { watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useReviewSubmission } from '../../../composables/v2/review/useReviewSubmission';
import type { SubmissionScope } from '../../../composables/v2/review/reviewSubmissionApi';
const props = defineProps<SubmissionScope & { dirty: boolean; busy: boolean; ready: boolean }>();
const submission = useReviewSubmission(() => ({ projectId: props.projectId, itemId: props.itemId, branchId: props.branchId, target: props.target }), () => props.dirty || props.busy || !props.ready);
watch(() => [props.projectId, props.itemId, props.branchId, props.target], () => { void submission.load(); }, { immediate: true });
</script>
<template>
 <section class="my-4 flex min-w-0 flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white p-3" aria-label="提交审核">
  <template v-if="submission.pending.value">
   <span class="text-sm font-medium text-amber-700">审核中</span>
   <Link :href="`/projects/${projectId}/reviews`" class="text-sm text-blue-700 underline">前往审核中心</Link>
  </template>
  <template v-else>
   <button type="button" class="rounded-md bg-slate-800 px-3 py-2 text-sm text-white disabled:opacity-50" :disabled="dirty || busy || !ready || submission.loading.value || submission.submitting.value || !!submission.error.value" @click="submission.submit()">{{ submission.submitting.value ? '提交中…' : submission.loading.value ? '加载审核状态…' : '提交审核' }}</button>
  </template>
  <p v-if="dirty" class="text-sm text-slate-600">请先保存当前修改，再提交审核。</p>
  <div v-if="submission.error.value" class="min-w-0 text-sm text-red-700" role="alert">{{ submission.error.value }} <button type="button" class="underline" :disabled="submission.loading.value || submission.submitting.value" @click="submission.load()">重试</button></div>
 </section>
</template>
