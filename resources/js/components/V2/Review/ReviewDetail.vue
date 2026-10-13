<script setup lang="ts">
// V2「待我审核」轻量审核中心 —— 审核详情。
// 正式动作只有：通过 / 退回修改 + 批注。
// 退回时展开批注 textarea（必填校验由 composable 处理）与轻量局部定位 target_label（可选）。
// 前端不计算审核结果：决策全部提交后端，POST 成功后由上层重新拉取列表。
import { ref } from 'vue';
import type { ReviewDetail, ReviewDecisionKind } from '../../../types/v2/review/review';
import { channelLabel, formatLabel } from '../../../composables/v2/review/reviewCore';
import Badge from '../../Badge.vue';
import LoadingState from '../../LoadingState.vue';
import ErrorState from '../../ErrorState.vue';

defineProps<{
  detail: ReviewDetail | null;
  loading: boolean;
  error: string;
  decisionPhase: 'idle' | 'submitting' | 'error';
  decisionError: string;
  comment: string;
  targetLabel: string;
}>();

const emit = defineEmits<{
  back: [];
  'update:comment': [value: string];
  'update:targetLabel': [value: string];
  approve: [];
  returnSubmit: [];
  returnCancel: [];
}>();

const showReturn = ref(false);

function openReturn(): void {
  showReturn.value = true;
}

function cancelReturn(): void {
  showReturn.value = false;
  emit('returnCancel');
}

function submitReturn(): void {
  emit('returnSubmit');
}

function approve(): void {
  showReturn.value = false;
  emit('approve');
}
</script>

<template>
  <div>
    <button
      type="button"
      class="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700"
      @click="emit('back')"
    >
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
      </svg>
      返回待审核列表
    </button>

    <LoadingState v-if="loading" label="加载审核详情…" />

    <ErrorState v-else-if="error" title="加载审核详情失败" :message="error">
      <template #action>
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          @click="emit('back')"
        >
          返回列表
        </button>
      </template>
    </ErrorState>

    <div v-else-if="detail" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <!-- 头部 -->
      <div class="border-b border-slate-100 px-4 py-4">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <h2 class="text-base font-semibold text-slate-800">{{ detail.content_title }}</h2>
          <span class="text-sm text-slate-400">来源 ›</span>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
          <span>{{ channelLabel(detail.channel) }} · {{ formatLabel(detail.format) }}</span>
          <Badge variant="active">{{ detail.review_target_label }}</Badge>
          <span class="text-slate-400">版本 {{ detail.version_label }}</span>
          <span class="text-slate-400">{{ new Date(detail.submitted_at).toLocaleString('zh-CN') }}</span>
        </div>
      </div>

      <!-- 预览区域（以内容预览为主） -->
      <div class="border-b border-slate-100 px-4 py-4">
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">内容预览</p>
        <p v-if="!detail.preview" class="text-sm text-slate-400">暂无预览内容。</p>

        <template v-else-if="detail.preview.kind === 'text'">
          <p class="whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ detail.preview.text ?? '' }}</p>
        </template>

        <template v-else-if="detail.preview.kind === 'lines'">
          <div
            v-for="(line, i) in detail.preview.lines ?? []"
            :key="i"
            class="whitespace-pre-wrap border-b border-slate-50 py-1.5 text-sm leading-6 text-slate-700 last:border-0"
          >
            {{ line }}
          </div>
        </template>

        <dl v-else-if="detail.preview.kind === 'items'" class="divide-y divide-slate-50">
          <div
            v-for="(item, i) in detail.preview.items ?? []"
            :key="i"
            class="grid grid-cols-[110px_1fr] gap-3 py-1.5 text-sm"
          >
            <dt class="text-slate-400">{{ item.label }}</dt>
            <dd class="whitespace-pre-wrap text-slate-700">{{ item.value ?? '—' }}</dd>
          </div>
        </dl>
      </div>

      <!-- 决策区 -->
      <div class="px-4 py-4">
        <p v-if="decisionPhase === 'error'" class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-600">
          {{ decisionError }}
        </p>

        <!-- 退回展开：批注 + 轻量局部定位 -->
        <div v-if="showReturn" class="mb-4 rounded-md border border-slate-200 bg-slate-50 p-3">
          <label class="mb-1 block text-sm font-medium text-slate-600" for="review-comment">
            批注
            <span class="font-normal text-slate-400">（退回修改必填）</span>
          </label>
          <textarea
            id="review-comment"
            rows="5"
            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
            :value="comment"
            placeholder="例如：P4 的画面证据与文案不一致，请补充门口等待的细节……"
            @input="emit('update:comment', ($event.target as HTMLTextAreaElement).value)"
          ></textarea>

          <label class="mb-1 mt-3 block text-sm font-medium text-slate-600" for="review-target">
            定位
            <span class="font-normal text-slate-400">（可选，如 P4 / 第 03 个视频片段 / 正文 / 封面建议）</span>
          </label>
          <input
            id="review-target"
            type="text"
            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
            :value="targetLabel"
            placeholder="P4"
            @input="emit('update:targetLabel', ($event.target as HTMLInputElement).value)"
          />
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            :disabled="decisionPhase === 'submitting'"
            class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
            @click="approve"
          >
            通过
          </button>

          <button
            v-if="!showReturn"
            type="button"
            :disabled="decisionPhase === 'submitting'"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
            @click="openReturn"
          >
            退回修改
          </button>

          <template v-if="showReturn">
            <button
              type="button"
              :disabled="decisionPhase === 'submitting'"
              class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
              @click="submitReturn"
            >
              确认退回
            </button>
            <button
              type="button"
              :disabled="decisionPhase === 'submitting'"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
              @click="cancelReturn"
            >
              取消
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
