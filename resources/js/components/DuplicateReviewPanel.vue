<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type {
  DuplicateDecision,
  DuplicateReviewCandidate,
  DuplicateReviewQuery,
  DuplicateReviewResult,
} from '../api/types';
import { DUPLICATE_FIELD_LABELS, DUPLICATE_SOURCE_LABELS, PAGE_TYPE_LABELS } from '../api/types';
import { duplicateReviewApi, type DuplicateReviewScope } from '../api/duplicateReview';
import { firstErrorMessage, is404 } from '../api/errors';
import { toast } from '../ui/toast';
import ErrorState from './ErrorState.vue';
import LoadingState from './LoadingState.vue';
import DuplicateCandidateCard from './DuplicateCandidateCard.vue';

// Duplicate Review panel for the Copy Editor.
//
// Deliberate constraints:
// - The panel holds no business truth of its own. `result` is whatever the server
//   last returned; there is no localStorage, no sessionStorage and no cached copy,
//   so a reload always re-reads the authoritative state.
// - The client computes nothing about similarity. It displays query_count,
//   candidate_count, match_kind, overlap_score and threshold exactly as received.
//   The only derived numbers are the review progress counters below, which count
//   rows the server already sent.
// - After a decision is accepted the whole result is re-read. We never patch
//   latest_decision locally, because decisions are append-only server-side and a
//   local patch could hide a concurrently appended decision.
const props = defineProps<{ scope: DuplicateReviewScope }>();

type Status = 'loading' | 'error' | 'ready';

const status = ref<Status>('loading');
const loadError = ref('');
const result = ref<DuplicateReviewResult | null>(null);
const submitting = ref(false);
// A rejected decision must not wipe the reviewer's context, so this banner is
// shown in place and the list is left untouched (no reload on failure).
const submitError = ref('');

const queryCount = computed(() => result.value?.query_count ?? 0);
const candidateCount = computed(() => result.value?.candidate_count ?? 0);

// Counting only walks what the server returned; it does not re-evaluate anything.
const candidates = computed<DuplicateReviewCandidate[]>(() => {
  const list: DuplicateReviewCandidate[] = [];
  for (const query of result.value?.queries ?? []) {
    for (const candidate of query.candidates) list.push(candidate);
  }
  return list;
});

const decidedCount = computed(
  () => candidates.value.filter((c) => c.latest_decision !== null).length,
);
const pendingCount = computed(() => candidateCount.value - decidedCount.value);
const allReviewed = computed(() => candidateCount.value > 0 && pendingCount.value === 0);

// Queries that actually produced at least one candidate, so the reviewer can skim
// only the fields that need a judgement.
const queriesWithCandidates = computed<DuplicateReviewQuery[]>(() =>
  (result.value?.queries ?? []).filter((q) => q.candidates.length > 0),
);

async function load(): Promise<void> {
  status.value = 'loading';
  loadError.value = '';
  try {
    result.value = await duplicateReviewApi.review(props.scope);
    status.value = 'ready';
  } catch (e) {
    result.value = null;
    loadError.value = is404(e)
      ? '当前项目、栏目、选题或篇目不在当前会话作用域内。'
      : (firstErrorMessage(e) ?? '查重结果加载失败');
    status.value = 'error';
  }
}

async function decide(
  query: DuplicateReviewQuery,
  candidate: DuplicateReviewCandidate,
  decision: DuplicateDecision,
  note: string | null,
): Promise<void> {
  submitting.value = true;
  submitError.value = '';
  try {
    await duplicateReviewApi.decide(props.scope, {
      query_page_version_id: query.page_version_id,
      query_field: query.field,
      match_page_version_id: candidate.match.page_version_id,
      match_field: candidate.match.field,
      decision,
      note,
    });
    // Re-read instead of patching locally: latest_decision must come from the server.
    await load();
    toast.success('已记录审核决定');
  } catch (e) {
    if (is404(e)) {
      submitError.value = '当前篇目或候选已不在当前会话作用域内，请刷新后重试。';
    } else {
      // 422 lands here too: the pairing is no longer valid (for example the working
      // version moved on). We keep the panel as-is so the reviewer keeps their input.
      submitError.value = firstErrorMessage(e) ?? '记录审核决定失败，请稍后重试。';
    }
  } finally {
    submitting.value = false;
  }
}

function retry(): void {
  void load();
}

onMounted(load);

// The Copy Editor calls this after the working copy changes, because a new working
// version changes both the query set and the validity of older decisions.
defineExpose({ reload: load });
</script>

<template>
  <section class="rounded-lg border border-slate-200 bg-white">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
      <div>
        <h2 class="text-base font-semibold text-slate-900">查重审核</h2>
        <p class="mt-1 text-xs text-slate-500">
          以当前工作稿为查询源，比对{{ DUPLICATE_SOURCE_LABELS.corpus }}。命中判定由服务端给出，这里只做人工确认。
        </p>
      </div>
      <button
        type="button"
        :disabled="status === 'loading'"
        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
        @click="retry"
      >
        {{ status === 'loading' ? '检查中…' : '重新检查' }}
      </button>
    </header>

    <div class="space-y-4 px-5 py-4">
      <LoadingState v-if="status === 'loading'" label="正在检查当前工作稿的重复候选…" />

      <ErrorState
        v-else-if="status === 'error'"
        title="查重结果加载失败"
        :message="loadError"
      >
        <template #action>
          <button
            type="button"
            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="retry"
          >
            重试
          </button>
        </template>
      </ErrorState>

      <template v-else>
        <!-- 汇总：四个数字全部来自服务端返回的计数 -->
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-md bg-slate-50 px-3 py-2">
            <dt class="text-xs text-slate-500">已检查字段</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900">{{ queryCount }}</dd>
          </div>
          <div class="rounded-md bg-slate-50 px-3 py-2">
            <dt class="text-xs text-slate-500">候选数量</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900">{{ candidateCount }}</dd>
          </div>
          <div class="rounded-md bg-slate-50 px-3 py-2">
            <dt class="text-xs text-slate-500">已处理</dt>
            <dd class="mt-1 text-lg font-semibold text-emerald-700">{{ decidedCount }}</dd>
          </div>
          <div class="rounded-md bg-slate-50 px-3 py-2">
            <dt class="text-xs text-slate-500">未处理</dt>
            <dd class="mt-1 text-lg font-semibold text-amber-700">{{ pendingCount }}</dd>
          </div>
        </dl>

        <!-- 提交失败提示：保留当前界面与输入，不刷新 -->
        <p
          v-if="submitError"
          class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
        >
          {{ submitError }}
        </p>

        <!-- Empty：区分「没有可检查的工作稿」与「已检查但无候选」 -->
        <p
          v-if="queryCount === 0"
          class="rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500"
        >
          当前篇目没有可检查的 Working Copy。请先在工作稿中填写标题、小字或收尾文案。
        </p>

        <p
          v-else-if="candidateCount === 0"
          class="rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500"
        >
          已检查 {{ queryCount }} 个字段，没有发现与同项目正式历史版本的重复候选。
        </p>

        <p
          v-else-if="allReviewed"
          class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
        >
          {{ candidateCount }} 个候选已全部处理完成。重新确认正式文案前可再复查一次。
        </p>

        <template v-else>
          <p class="text-xs text-slate-500">
            还有 {{ pendingCount }} 个候选待处理。请对照左右两侧文案后给出判断。
          </p>

          <div class="space-y-5">
            <div
              v-for="query in queriesWithCandidates"
              :key="`${query.page_version_id}-${query.field}`"
              class="space-y-3"
            >
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-900">
                  第 {{ query.page_no }} 页 · {{ PAGE_TYPE_LABELS[query.page_type] }}
                </h3>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                  {{ DUPLICATE_FIELD_LABELS[query.field] }}
                </span>
                <span class="text-xs text-slate-400">{{ query.candidates.length }} 个候选</span>
              </div>

              <DuplicateCandidateCard
                v-for="candidate in query.candidates"
                :key="candidate.match.page_version_id"
                :query="query"
                :candidate="candidate"
                :busy="submitting"
                @decide="
                  (decision, note) => decide(query, candidate, decision as DuplicateDecision, note)
                "
              />
            </div>
          </div>
        </template>
      </template>
    </div>
  </section>
</template>
