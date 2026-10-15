<script setup lang="ts">
// V2-P3-C04 独立开发预览 App（DEV ONLY）—— 挂载「待我审核」轻量审核中心。
// 不依赖 Laravel / Inertia / 后端 API；数据为固定示例（明确标注 DEV），
// 决策使用显式标注的 MOCK providers（reviewDevMock.ts，正式生产模块不引用）。
// 正式页面必须走 resources/js/pages/V2/Review/ReviewCenter.vue + reviewApi。
import { onMounted } from 'vue';
import { useReviewCenter } from '../../../composables/v2/review/useReviewCenter';
import {
  buildMockReviewList,
  buildMockReviewDetail,
  applyMockDecision,
} from '../../../composables/v2/review/reviewDevMock';
import ReviewList from './ReviewList.vue';
import ReviewDetail from './ReviewDetail.vue';
import ToastHost from '../../ToastHost.vue';

const center = useReviewCenter(1, {
  providers: {
    list: () => buildMockReviewList(),
    detail: (id) =>
      buildMockReviewDetail(id).then((d) => {
        if (!d) throw new Error('审核项不存在（DEV MOCK 404）');
        return d;
      }),
    decide: (id, input) => applyMockDecision(id, input),
  },
});

const showDetail = () => center.selectedId.value != null;

onMounted(() => {
  center.loadList();
});
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
      DEV PREVIEW：固定示例数据，决策为内存 Mock（正式决策由后端 API 提供，POST 成功后重新拉取列表）；
      正式页面为 ReviewCenter.vue，数据由 WorkBuddy 后端接线后提供。
    </div>

    <template v-if="!showDetail()">
      <div class="mb-4">
        <h1 class="text-lg font-semibold text-slate-800">待我审核</h1>
        <p class="mt-1 text-sm text-slate-500">
          共 {{ center.items.value.length }} 条待审核内容
        </p>
      </div>

      <ReviewList
        :items="center.items.value"
        :loading="center.loading.value"
        :load-error="center.loadError.value"
        :selected-id="center.selectedId.value"
        @open="center.openDetail($event)"
        @retry="center.loadList()"
      />
    </template>

    <ReviewDetail
      v-else
      :detail="center.detail.value"
      :loading="center.detailLoading.value"
      :error="center.detailError.value"
      :decision-phase="center.decisionPhase.value"
      :decision-error="center.decisionError.value"
      :comment="center.comment.value"
      :target-label="center.targetLabel.value"
      @back="center.closeDetail()"
      @update:comment="center.comment.value = $event"
      @update:target-label="center.targetLabel.value = $event"
      @approve="center.submitDecision('approved')"
      @return-submit="center.submitDecision('changes_requested')"
      @return-cancel="center.resetDecision()"
    />

    <ToastHost />
  </div>
</template>
