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

  const providers: ReviewImportProviders = options.providers ?? {
    list: () => reviewApi.list(projectId),
    detail: (id) => reviewApi.detail(projectId, id),
    decide: (id, input) => reviewApi.decide(projectId, id, input),
  };

  async function loadList(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    try {
      const all = await providers.list();
      items.value = pendingOnly(all);
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载待审核列表失败';
    } finally {
      loading.value = false;
    }
  }

  async function openDetail(id: number): Promise<void> {
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
      if (!d) {
        detailError.value = '审核项不存在或已处理。';
      } else {
        detail.value = d;
      }
    } catch (e) {
      detailError.value = firstErrorMessage(e) ?? '加载详情失败';
    } finally {
      detailLoading.value = false;
    }
  }

  function closeDetail(): void {
    selectedId.value = null;
    detail.value = null;
    detailError.value = '';
  }

  // ---- 决策 ----
  async function submitDecision(decision: ReviewDecisionKind): Promise<void> {
    if (selectedId.value == null) return;
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
      await loadList();
      closeDetail();
    } catch (e) {
      decisionError.value = firstErrorMessage(e) ?? '提交决策失败';
      decisionPhase.value = 'error';
    }
  }

  function resetDecision(): void {
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
