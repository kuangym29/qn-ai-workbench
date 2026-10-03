<script setup lang="ts">
import { computed, ref } from 'vue';
import type {
  DuplicateDecision,
  DuplicateFieldKey,
  DuplicateMatchKind,
  DuplicateReviewCandidate,
  DuplicateReviewQuery,
} from '../api/types';
import {
  DUPLICATE_DECISION_LABELS,
  DUPLICATE_DECISION_ORDER,
  DUPLICATE_FIELD_LABELS,
  DUPLICATE_MATCH_KIND_LABELS,
  PAGE_TYPE_LABELS,
} from '../api/types';
import Badge from './Badge.vue';

// One candidate = one working-copy field (the query) versus one field of a formal
// historical page version (the match). Everything shown here comes from the server:
// the texts, the match tier, the score and the threshold. The card formats and
// labels, it never judges. A review is recorded by emitting `decide`; the parent
// performs the POST and re-reads the result, so no decision is ever applied locally.
const props = defineProps<{
  query: DuplicateReviewQuery;
  candidate: DuplicateReviewCandidate;
  busy?: boolean;
}>();

const emit = defineEmits<{
  (e: 'decide', decision: DuplicateDecision, note: string | null): void;
}>();

// The optional note is component-local so a rejected submit (422) can keep whatever
// the reviewer typed.
const note = ref('');

const KIND_VARIANT: Record<DuplicateMatchKind, 'danger' | 'planning' | 'default'> = {
  original_exact: 'danger',
  normalized_exact: 'planning',
  overlap: 'default',
};

const kindVariant = computed(() => KIND_VARIANT[props.candidate.match_kind]);
const kindLabel = computed(() => DUPLICATE_MATCH_KIND_LABELS[props.candidate.match_kind]);

// Percentages are a pure rendering of the server's 0..1 values. No arithmetic
// judgement happens here beyond * 100 for display.
const scorePercent = computed(() => (props.candidate.overlap_score * 100).toFixed(1));
const thresholdPercent = computed(() => (props.candidate.threshold * 100).toFixed(1));

const match = computed(() => props.candidate.match);
const fieldLabel = computed(
  () => DUPLICATE_FIELD_LABELS[props.candidate.match.field as DuplicateFieldKey],
);
const latest = computed(() => props.candidate.latest_decision);

function submit(value: DuplicateDecision): void {
  const trimmed = note.value.trim();
  emit('decide', value, trimmed === '' ? null : trimmed);
}

function clearNote(): void {
  note.value = '';
}
</script>

<template>
  <article class="rounded-md border border-slate-200 bg-white">
    <!-- 判定摘要：类型 + 相似度 + 阈值，全部来自服务端 -->
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
      <div class="flex flex-wrap items-center gap-2">
        <Badge :variant="kindVariant" :label="kindLabel" />
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
          {{ fieldLabel }}
        </span>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
          第 {{ match.page_no }} 页 · {{ PAGE_TYPE_LABELS[match.page_type] }}
        </span>
      </div>
      <div class="flex items-center gap-3 text-xs text-slate-500">
        <span>
          相似度
          <span class="ml-1 text-sm font-semibold text-slate-900">{{ scorePercent }}%</span>
        </span>
        <span>
          判定阈值
          <span class="ml-1">{{ thresholdPercent }}%</span>
        </span>
      </div>
    </header>

    <!-- 左右对照：当前工作稿 vs 正式历史版本 -->
    <div class="grid gap-3 px-4 py-3 sm:grid-cols-2">
      <div class="rounded-md border border-sky-200 bg-sky-50/60 px-3 py-2">
        <p class="text-xs font-medium text-sky-700">当前工作稿 · 第 {{ query.page_no }} 页</p>
        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-800">{{ query.text }}</p>
      </div>
      <div class="rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2">
        <p class="text-xs font-medium text-amber-700">历史正式版本 · 第 {{ match.page_no }} 页</p>
        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-800">{{ match.text }}</p>
      </div>
    </div>

    <!-- 来源信息不隐藏：篇目、Revision、页码、命中类型都摊开给审核人看 -->
    <dl
      class="grid grid-cols-2 gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-3 text-xs text-slate-500 sm:grid-cols-4"
    >
      <div class="flex gap-1">
        <dt>来源篇目</dt>
        <dd class="font-medium text-slate-700">#{{ match.content_item_id }}</dd>
      </div>
      <div class="flex gap-1">
        <dt>Revision</dt>
        <dd class="font-medium text-slate-700">#{{ match.revision_no }}</dd>
      </div>
      <div class="flex gap-1">
        <dt>页码</dt>
        <dd class="font-medium text-slate-700">{{ match.page_no }}</dd>
      </div>
      <div class="flex gap-1">
        <dt>命中类型</dt>
        <dd class="font-medium text-slate-700">{{ kindLabel }}</dd>
      </div>
    </dl>

    <!-- 已记录的决定（append-only，这里只展示最新一条） -->
    <div v-if="latest" class="border-t border-slate-100 px-4 py-3">
      <p class="text-xs text-slate-500">
        最新决定
        <span class="ml-1 font-medium text-slate-800">
          {{ DUPLICATE_DECISION_LABELS[latest.decision] }}
        </span>
        <span class="ml-1 text-slate-400">#{{ latest.decision_no }}</span>
      </p>
      <p v-if="latest.note" class="mt-1 whitespace-pre-wrap text-xs text-slate-600">
        备注：{{ latest.note }}
      </p>
    </div>

    <!-- 人工动作 -->
    <footer class="space-y-2 border-t border-slate-100 px-4 py-3">
      <input
        v-model="note"
        type="text"
        placeholder="备注（可选）"
        :disabled="busy"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-slate-900 focus:outline-none disabled:opacity-50"
      />
      <div class="flex flex-wrap gap-2">
        <button
          v-for="option in DUPLICATE_DECISION_ORDER"
          :key="option"
          type="button"
          :disabled="busy"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          @click="submit(option)"
        >
          {{ DUPLICATE_DECISION_LABELS[option] }}
        </button>
        <button
          v-if="note !== ''"
          type="button"
          :disabled="busy"
          class="rounded-md px-3 py-1.5 text-sm text-slate-500 hover:text-slate-700 disabled:opacity-50"
          @click="clearNote"
        >
          清空备注
        </button>
      </div>
    </footer>
  </article>
</template>
