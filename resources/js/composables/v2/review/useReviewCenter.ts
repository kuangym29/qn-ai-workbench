// V2「待我审核」轻量审核中心 —— 状态机 composable。
//
// 正式链路：list（pending）→ 打开 detail → approve / return + comment → POST decision
// → 后端 append-only → 重新拉取 pending 列表。
// 前端不伪造服务端状态；开发预览注入显式 MOCK providers（见 ReviewDevPreviewApp，
// MOCK 位于 reviewDevMock.ts，正式生产模块不引用）。
import { ref } from 'vue';
import type {
  ReviewDecisionKind,
  ReviewDetail,
  ReviewImportProviders,
  ReviewItem,
} from '../../../types/v2/review/review';
import { pendingOnly } from './reviewCore';
import { reviewApi } from './reviewApi';
import { firstErrorMessage } from '../../../api/errors';
import { toast } from '../../../ui/toast';

export interface ReviewCenterOptions {
  /** 默认走后端；开发预览注入 MOCK。 */
  providers?: ReviewImportProviders;
}

export type ReviewDecisionPhase = 'idle' | 'submitting' | 'error';

export function useReviewCenter(
  projectId: number | string,
  options: ReviewCenterOptions = {},
) {
  const items = ref<ReviewItem[]>([]);
  const loading = ref(true);
  const loadError = ref('');

  // ---- 详情子状态 ----
  const selectedId = ref<number | null>(null);
  const detail = ref<ReviewDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref('');

  // ---- 决策子状态 ----
  const decisionPhase = ref<ReviewDecisionPhase>('idle');
  const decisionError = ref('');
  const comment = ref('');
  const targetLabel = ref('');
  let detailRequest = 0;
  let listRequest = 0;

  const providers: ReviewImportProviders = options.providers ?? {
    list: () => reviewApi.list(projectId),
    detail: (id) => reviewApi.detail(projectId, id),
    decide: (id, input) => reviewApi.decide(projectId, id, input),
  };

  async function fetchList(): Promise<void> {
    const request = ++listRequest;
    loading.value = true;
    loadError.value = '';
    try {
      const all = await providers.list();
      if (request === listRequest) items.value = pendingOnly(all);
    } catch (e) {
      if (request === listRequest) loadError.value = firstErrorMessage(e) ?? '加载待审核列表失败';
    } finally {
      if (request === listRequest) loading.value = false;
    }
  }
  async function loadList(): Promise<void> {
    if (decisionPhase.value === 'submitting') return;
    await fetchList();
  }

  async function openDetail(id: number): Promise<void> {
    if (decisionPhase.value === 'submitting') return;
    const request = ++detailRequest;
    selectedId.value = id;
    detail.value = null;
    detailError.value = '';
    detailLoading.value = true;
    decisionPhase.value = 'idle';
    decisionError.value = '';
    comment.value = '';
    targetLabel.value = '';
    try {
      const d = await providers.detail(id);
      if (request !== detailRequest || selectedId.value !== id) return;
      if (!d) {
        detailError.value = '审核项不存在或已处理。';
      } else {
        detail.value = d;
      }
    } catch (e) {
      if (request === detailRequest) detailError.value = firstErrorMessage(e) ?? '加载详情失败';
    } finally {
      if (request === detailRequest) detailLoading.value = false;
    }
  }

  function closeDetail(): void {
    if (decisionPhase.value === 'submitting') return;
    ++detailRequest;
    detailLoading.value = false;
    selectedId.value = null;
    detail.value = null;
    detailError.value = '';
  }

  // ---- 决策 ----
  async function submitDecision(decision: ReviewDecisionKind): Promise<void> {
    if (selectedId.value == null || !detail.value || detailLoading.value || decisionPhase.value === 'submitting') return;
    if (decision === 'changes_requested' && !comment.value.trim()) {
      decisionError.value = '退回修改需要填写批注。';
      decisionPhase.value = 'error';
      return;
    }
    decisionPhase.value = 'submitting';
    decisionError.value = '';
    try {
      await providers.decide(selectedId.value, {
        decision,
        comment: comment.value.trim() || null,
        target_label: targetLabel.value.trim() || null,
      });
      toast.success(decision === 'approved' ? '已通过' : '已退回修改');
      // 决策成功后不乐观伪造状态：重新拉取 pending 列表。
      await fetchList();
      decisionPhase.value = 'idle';
      closeDetail();
    } catch (e) {
      decisionError.value = firstErrorMessage(e) ?? '提交决策失败';
      decisionPhase.value = 'error';
    }
  }

  function resetDecision(): void {
    if (decisionPhase.value === 'submitting') return;
    decisionPhase.value = 'idle';
    decisionError.value = '';
    comment.value = '';
    targetLabel.value = '';
  }

  return {
    items,
    loading,
    loadError,
    selectedId,
    detail,
    detailLoading,
    detailError,
    decisionPhase,
    decisionError,
    comment,
    targetLabel,
    loadList,
    openDetail,
    closeDetail,
    submitDecision,
    resetDecision,
  };
}
