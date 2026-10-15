<script setup lang="ts">
// V2「待我审核」轻量审核中心 —— 正式 Inertia 页面。
// 数据从 API 加载（reviewApi，正式后端由 WorkBuddy Phase 2 实现）。
// 页面只依赖 projectId prop，不根据 URL 自动选项目。
//
// 注意：本任务未修改 routes/web.php；此页面的正式路由由 Work 主程序集成时接线。
import { onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../layouts/AdminLayout.vue';
import PageHeader from '../../../components/PageHeader.vue';
import Breadcrumb from '../../../components/Breadcrumb.vue';
import { useReviewCenter } from '../../../composables/v2/review/useReviewCenter';
import { is404 } from '../../../api/errors';
import { toast } from '../../../ui/toast';
import ReviewList from '../../../components/V2/Review/ReviewList.vue';
import ReviewDetail from '../../../components/V2/Review/ReviewDetail.vue';

const props = defineProps<{
  projectId: string | number;
}>();

const center = useReviewCenter(props.projectId);

function handleScopeError(e: unknown): void {
  if (is404(e)) {
    toast.error('当前项目不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  center.loadError.value = '加载失败';
}

onMounted(async () => {
  try {
    await center.loadList();
  } catch (e) {
    handleScopeError(e);
  }
});
</script>

<template>
  <AdminLayout>
    <Breadcrumb
      :items="[
        { label: '项目', href: '/projects' },
        { label: '待我审核' },
      ]"
    />

    <PageHeader
      title="待我审核"
      :description="`共 ${center.items.value.length} 条待审核内容，自动聚合各内容形式的新提交。`"
    >
      <template #actions>
        <Link
          :href="`/projects/${projectId}/content`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回内容库
        </Link>
      </template>
    </PageHeader>

    <template v-if="center.selectedId.value == null">
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
  </AdminLayout>
</template>
