import { computed, ref } from 'vue';
import { firstErrorMessage } from '../../../api/errors';
import { reviewSubmissionApi } from './reviewSubmissionApi';
import type { PendingReview, SubmissionScope } from './reviewSubmissionApi';

export function useReviewSubmission(scope: () => SubmissionScope, blocked: () => boolean, client = reviewSubmissionApi) {
 const review = ref<PendingReview | null>(null);
 const loading = ref(false);
 const submitting = ref(false);
 const error = ref('');
 let revision = 0;
 const key = () => JSON.stringify(scope());
 const args = (s: SubmissionScope): [number|string,number|string,number|string,SubmissionScope['target']] => [s.projectId,s.itemId,s.branchId,s.target];
 async function load(): Promise<void> {
  const request = ++revision, current = key();
  review.value = null; loading.value = true; error.value = '';
  try {
   const result = await client.load(...args(scope()));
   if (request === revision && current === key()) review.value = result.pending ? result.review : null;
  } catch (e) {
   if (request === revision && current === key()) error.value = firstErrorMessage(e) ?? '审核状态加载失败，请重试';
  } finally { if (request === revision && current === key()) loading.value = false; }
 }
 async function submit(): Promise<boolean> {
  if (blocked() || loading.value || submitting.value || review.value) return false;
  const current = key(); submitting.value = true; error.value = '';
  try {
   await client.submit(...args(scope()));
   if (current !== key()) return false;
   await load();
   return !error.value;
  } catch (e) {
   if (current === key()) error.value = firstErrorMessage(e) ?? '提交审核失败，请重试';
   return false;
  } finally { submitting.value = false; }
 }
 return { review, pending: computed(() => review.value !== null), loading, submitting, error, load, submit };
}
