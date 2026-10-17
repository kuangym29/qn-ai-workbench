<script setup lang="ts">
// 人物 / 场景复用资产索引 —— 页面主体。
// 一个页面、两个 Tab、一个筛选；不做上传 / 文件夹 / 标签树 / 版本树。
// 缺失卡 [准备建立] 只 emit prepareCharacter / prepareScene（第一阶段不调 Work）。
import EmptyState from '../../EmptyState.vue';
import ErrorState from '../../ErrorState.vue';
import LoadingState from '../../LoadingState.vue';
import ReusableAssetCard from './ReusableAssetCard.vue';
import type {
  AssetStatusFilter,
  AssetTab,
  ReusableAssetItem,
} from '../../../types/v2/reusableAssets/reusableAssets';

interface Counts {
  existing: number;
  missing: number;
  total: number;
}

const props = defineProps<{
  loading: boolean;
  loadError: string;
  tab: AssetTab;
  statusFilter: AssetStatusFilter;
  characterCounts: Counts;
  sceneCounts: Counts;
  tabCounts: Counts;
  visibleItems: ReusableAssetItem[];
  isEmpty: boolean;
}>();

const emit = defineEmits<{
  back: [];
  switchTab: [tab: AssetTab];
  changeFilter: [filter: AssetStatusFilter];
  retry: [];
  create: [type: AssetTab];
  prepareCharacter: [item: ReusableAssetItem];
  prepareScene: [item: ReusableAssetItem];
  viewCharacter: [item: ReusableAssetItem];
  viewScene: [item: ReusableAssetItem];
}>();

const tabs: { key: AssetTab; label: string; counts: () => Counts }[] = [
  { key: 'character', label: '人物', counts: () => props.characterCounts },
  { key: 'scene', label: '场景', counts: () => props.sceneCounts },
];

const filters: { key: AssetStatusFilter; label: string }[] = [
  { key: 'all', label: '全部' },
  { key: 'existing', label: '已有' },
  { key: 'missing', label: '缺失' },
];

function onCardPrepare(item: ReusableAssetItem) {
  if (item.type === 'character') emit('prepareCharacter', item);
  else emit('prepareScene', item);
}

function onCardView(item: ReusableAssetItem) {
  if (item.type === 'character') emit('viewCharacter', item);
  else emit('viewScene', item);
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-4">
    <!-- 头部 -->
    <div class="rounded-lg border border-slate-200 bg-white p-5">
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50"
          @click="emit('back')"
        >
          返回
        </button>
        <h1 class="text-base font-semibold text-slate-800">人物 / 场景复用索引</h1>
      </div>
      <p class="mt-2 text-xs leading-5 text-slate-500">
        只记录长期重复出现的固定人物身份与稳定场景母版，便于制作时快速判断能否复用、还缺什么。
        时间、光线、天气、临时道具与人物位置等每次使用变化不进入索引。
      </p>
    </div>

    <!-- Tab + 筛选 + 新增 -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex rounded-lg border border-slate-200 bg-white p-1" data-v2-asset-tabs>
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm"
          :class="tab === t.key ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-100'"
          @click="emit('switchTab', t.key)"
        >
          {{ t.label }}（{{ t.counts().total }}）
        </button>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <div class="flex rounded-lg border border-slate-200 bg-white p-1" data-v2-asset-filters>
          <button
            v-for="f in filters"
            :key="f.key"
            type="button"
            class="rounded-md px-3 py-1.5 text-xs"
            :class="statusFilter === f.key ? 'bg-slate-100 text-slate-800' : 'text-slate-500 hover:bg-slate-50'"
            @click="emit('changeFilter', f.key)"
          >
            {{ f.label }}
            <span class="text-slate-400">{{
              f.key === 'all' ? tabCounts.total : f.key === 'existing' ? tabCounts.existing : tabCounts.missing
            }}</span>
          </button>
        </div>

        <button
          type="button"
          class="rounded-md bg-slate-800 px-3 py-1.5 text-xs text-white hover:bg-slate-700"
          data-v2-asset-create
          @click="emit('create', tab)"
        >
          新增{{ tab === 'character' ? '人物' : '场景' }}
        </button>
      </div>
    </div>

    <!-- 列表 / 状态 -->
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :message="loadError">
      <template #action>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
          @click="emit('retry')"
        >
          重新加载
        </button>
      </template>
    </ErrorState>
    <EmptyState
      v-else-if="isEmpty"
      :title="statusFilter === 'all' ? '当前还没有' + (tab === 'character' ? '人物' : '场景') + '母版' : '当前筛选下没有匹配项'"
      :description="statusFilter === 'missing' ? '所有' + (tab === 'character' ? '人物' : '场景') + '均已有稳定母版。' : '可在后续制作中建立稳定母版。'"
    />
    <div v-else class="grid gap-3 sm:grid-cols-2" data-v2-asset-grid>
      <ReusableAssetCard
        v-for="item in visibleItems"
        :key="item.id"
        :item="item"
        @view="onCardView"
        @prepare="onCardPrepare"
      />
    </div>
  </div>
</template>
