<script setup lang="ts">
// V2 图片批次生产 —— 工作台主体（纯展示 + 局部交互，不依赖 Inertia / API）。
// 布局：
//   顶部（内容名称 / 公众号·图片文章 / 来源 ›）
//   → 整批生图说明（大文本区）
//   → 整批备注（弱化文本区）
//   → 批次资产检查摘要（整批聚合一次）
//   → 页面单元列表（勾选 + 轻量修正 + 单页状态）
//   → 执行动作区（整批生成 / 先做试样 / 选择部分页面生成 / 复制任务 / 查看历史）
//   → 批次历史（轻量）
import { computed } from 'vue';
import type {
  AssetCheck,
  BatchHistoryItem,
  ImageBatchExecutionMode,
  ImageBatchRunPhase,
  ImageBatchUnit,
} from '../../../types/v2/imageBatch/imageBatch';
import { executionLabel, selectionSummary } from '../../../composables/v2/imageBatch/imageBatchCore';
import LoadingState from '../../LoadingState.vue';
import ErrorState from '../../ErrorState.vue';
import ImageBatchAssetSummary from './ImageBatchAssetSummary.vue';
import ImageBatchUnitCard from './ImageBatchUnitCard.vue';
import ImageBatchBatchHistory from './ImageBatchBatchHistory.vue';

const props = defineProps<{
  contentTitle: string;
  sourceLabel: string;
  batchInstruction: string;
  batchNote: string | null;
  assets: AssetCheck[];
  units: ImageBatchUnit[];
  history: BatchHistoryItem[];
  loading: boolean;
  loadError: string;
  runPhase: ImageBatchRunPhase;
  runError: string;
  copied: boolean;
  historyOpen: boolean;
  allSelected: boolean;
  changedCount: number;
  busy: boolean;
}>();

const emit = defineEmits<{
  'update:batchInstruction': [value: string];
  'update:batchNote': [value: string];
  toggleUnit: [unit: ImageBatchUnit];
  selectAll: [];
  clearSelection: [];
  updateUnit: [unit: ImageBatchUnit];
  run: [mode: ImageBatchExecutionMode];
  copyTask: [];
  toggleHistory: [];
  viewChange: [unit: ImageBatchUnit];
  keepCurrent: [unit: ImageBatchUnit];
  regenerate: [unit: ImageBatchUnit];
  back: [];
}>();

const selection = computed(() => selectionSummary(props.units));
const disabled = computed(() => props.loading || props.busy);
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-6">
    <!-- 顶部：内容名称 / 公众号·图片文章 / 来源 › -->
    <header class="rounded-lg border border-slate-200 bg-white p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-semibold text-slate-900">{{ contentTitle || '图片批次生产' }}</h1>
          <p class="mt-1 text-sm text-slate-500">公众号 · 图片文章</p>
          <p class="mt-0.5 text-xs text-slate-400">来源 › {{ sourceLabel }}</p>
        </div>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
          @click="emit('back')"
        >
          返回
        </button>
      </div>
    </header>

    <LoadingState v-if="loading" label="加载图片批次…" />
    <ErrorState v-else-if="loadError" title="加载失败" :message="loadError">
      <template #action>
        <button
          type="button"
          class="rounded-md border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-100"
          @click="emit('back')"
        >
          返回
        </button>
      </template>
    </ErrorState>

    <template v-else>
      <!-- 整批生图说明（一个大文本区，不拆字段） -->
      <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-slate-900">整批生图说明</h2>
        <p class="mt-0.5 text-xs text-slate-400">描述这组图片的视觉意图、人物一致性、场景一致性、光线 / 风格与共同要求。</p>
        <textarea
          :disabled="disabled"
          :value="batchInstruction"
          rows="5"
          class="mt-2 w-full resize-y rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-slate-400 focus:bg-white focus:outline-none"
          placeholder="例如：整组温暖柔和的家庭日常风，人物保持一致，场景自然光……"
          @input="emit('update:batchInstruction', ($event.target as HTMLTextAreaElement).value)"
        />
      </section>

      <!-- 整批备注（弱化文本区） -->
      <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-medium text-slate-700">整批备注</h2>
        <p class="mt-0.5 text-xs text-slate-400">只记录当前这一批的特殊补充；已有 SOP / 品牌规范由系统处理。</p>
        <textarea
          :disabled="disabled"
          :value="batchNote ?? ''"
          rows="2"
          class="mt-2 w-full resize-y rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-slate-400 focus:bg-white focus:outline-none"
          placeholder="本批特殊备注（可选）"
          @input="emit('update:batchNote', ($event.target as HTMLTextAreaElement).value)"
        />
      </section>

      <!-- 批次资产检查摘要（整批聚合一次） -->
      <ImageBatchAssetSummary :assets="assets" />

      <!-- 来源内容变化汇总（轻量） -->
      <div
        v-if="changedCount > 0"
        class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        role="status"
      >
        ⚠ {{ changedCount }} 页的来源内容后来有修改，建议检查这些页面的图片是否受影响。
      </div>

      <!-- 页面单元列表 -->
      <fieldset :disabled="disabled" aria-label="页面单元列表">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold text-slate-900">页面单元</h2>
          <div class="flex items-center gap-3 text-xs text-slate-500">
            <span>已选 {{ selection.selected }} / {{ selection.total }} 页</span>
            <label class="flex items-center gap-1">
              <input
                type="checkbox"
                class="h-4 w-4 rounded border-slate-300"
                :checked="allSelected"
                @change="emit(allSelected ? 'clearSelection' : 'selectAll')"
              />
              全选
            </label>
            <button type="button" class="underline hover:text-slate-700" @click="emit('clearSelection')">取消选择</button>
          </div>
        </div>
        <div v-if="units.length" class="grid gap-3 lg:grid-cols-2" data-v2-image-batch-units>
          <ImageBatchUnitCard
            v-for="unit in units"
            :key="String(unit.id)"
            :unit="unit"
            :disabled="disabled"
            @toggle="emit('toggleUnit', $event)"
            @update="emit('updateUnit', $event)"
            @view-change="emit('viewChange', $event)"
            @keep-current="emit('keepCurrent', $event)"
            @regenerate="emit('regenerate', $event)"
          />
        </div>
        <div
          v-else
          class="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center"
        >
          <p class="text-sm font-medium text-slate-600">还没有页面单元</p>
          <p class="mt-1 text-sm text-slate-400">系统将根据内容结构自动拆出每一页。</p>
        </div>
      </fieldset>

      <!-- 执行动作区 -->
      <section class="rounded-lg border border-slate-200 bg-white p-4" data-v2-image-batch-actions>
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            :disabled="disabled || runPhase === 'running'"
            class="rounded-md bg-slate-900 px-5 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            @click="emit('run', 'full')"
          >
            {{ runPhase === 'running' ? '提交中…' : '整批生成' }}
          </button>
          <button
            type="button"
            :disabled="disabled || runPhase === 'running'"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            @click="emit('run', 'trial')"
          >
            先做试样
          </button>
          <button
            type="button"
            :disabled="disabled || runPhase === 'running'"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            @click="emit('run', 'partial')"
          >
            选择部分页面生成
          </button>
          <button
            type="button"
            :disabled="disabled || runPhase === 'running'"
            class="rounded-md border border-dashed border-slate-300 px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 disabled:opacity-50"
            @click="emit('copyTask')"
          >
            {{ copied ? '已复制整批任务' : '复制整批生图任务' }}
          </button>
        </div>
        <p class="mt-2 text-xs text-slate-400">
          试样与部分生成使用上方勾选（当前已选 {{ selection.selected }} 页）；整批生成覆盖全部页面。
        </p>
        <p v-if="runError" role="status" class="mt-2 text-xs text-rose-700">{{ runError }}</p>
      </section>

      <!-- 批次历史（轻量） -->
      <ImageBatchBatchHistory :history="history" :open="historyOpen" @toggle="emit('toggleHistory')" />
    </template>
  </div>
</template>
