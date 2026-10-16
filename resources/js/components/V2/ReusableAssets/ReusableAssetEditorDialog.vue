<script setup lang="ts">
// 人物 / 场景复用资产 —— 新增 / 详情编辑弹层。
//
// 同一个层承担两件事（P5-I02）：
//   - create：新增人物 / 场景（名称可填，类型由当前 Tab 决定）
//   - edit  ：「查看」与「准备建立」共用：名称 / 类型 / 最近使用 只读，
//              状态 / 预览图地址 / 说明 可编辑并保存
//
// 刻意只做「轻量资产入口」：没有上传、没有删除、没有标签 / 文件夹 / 版本。
import { computed, ref, watch } from 'vue';
import type {
  AssetEditorForm,
  ReusableAssetItem,
  ReusableAssetStatus,
  ReusableAssetType,
} from '../../../types/v2/reusableAssets/reusableAssets';
import { formatLastUsed, isAssetNameValid } from '../../../composables/v2/reusableAssets/assetIndexCore';

const props = defineProps<{
  open: boolean;
  mode: 'create' | 'edit';
  type: ReusableAssetType;
  item: ReusableAssetItem | null;
  saving: boolean;
  error: string;
}>();

const emit = defineEmits<{
  close: [];
  /** 提交原始表单（字符串态）；归一 / 构造载荷由 core 的纯函数在调用方完成。 */
  submit: [form: AssetEditorForm];
}>();

/** 状态 UI 使用业务中文（不直接暴露 existing / missing）。 */
const STATUS_OPTIONS: { value: ReusableAssetStatus; label: string }[] = [
  { value: 'existing', label: '已有稳定母版' },
  { value: 'missing', label: '待建立' },
];

const name = ref('');
const status = ref<ReusableAssetStatus>('missing');
const thumbnailUrl = ref('');
const description = ref('');
const localError = ref('');

const isEdit = computed(() => props.mode === 'edit');
const typeLabel = computed(() => (props.type === 'character' ? '人物' : '场景'));
const title = computed(() => (isEdit.value ? `${typeLabel.value}详情` : `新增${typeLabel.value}`));

function reset(): void {
  localError.value = '';
  if (isEdit.value && props.item) {
    name.value = props.item.name;
    status.value = props.item.status;
    thumbnailUrl.value = props.item.thumbnail_url ?? '';
    description.value = props.item.description ?? '';
  } else {
    name.value = '';
    status.value = 'missing';
    thumbnailUrl.value = '';
    description.value = '';
  }
}

watch(
  () => [props.open, props.mode, props.item?.id] as const,
  () => {
    if (props.open) reset();
  },
  { immediate: true },
);

function onSubmit(): void {
  localError.value = '';

  if (!isEdit.value && !isAssetNameValid(name.value)) {
    localError.value = '名称不能为空。';
    return;
  }

  emit('submit', {
    name: name.value,
    status: status.value,
    thumbnail_url: thumbnailUrl.value,
    description: description.value,
  });
}

const shownError = computed(() => localError.value || props.error);
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4"
    data-v2-asset-dialog
    role="dialog"
    aria-modal="true"
    :aria-label="title"
    @click.self="emit('close')"
  >
    <div class="my-6 w-full max-w-lg rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="truncate text-base font-semibold text-slate-800">{{ title }}</h2>
          <p class="mt-1 text-xs text-slate-500">
            只记录长期稳定复用的母版；预览图填地址即可，本版不做文件上传。
          </p>
        </div>
        <button
          type="button"
          class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50"
          data-v2-asset-dialog-close
          @click="emit('close')"
        >
          关闭
        </button>
      </div>

      <div class="mt-4 space-y-4">
        <!-- 名称（edit 只读） -->
        <div>
          <label class="block text-xs font-medium text-slate-600" :for="'asset-name-' + type">名称</label>
          <input
            :id="'asset-name-' + type"
            v-model="name"
            type="text"
            :readonly="isEdit"
            data-v2-field="name"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800"
            :class="isEdit ? 'bg-slate-100 text-slate-500' : ''"
            placeholder="如：妈妈 / 客厅"
          />
          <p v-if="isEdit" class="mt-1 text-[11px] text-slate-400">名称与类型是资产的稳定身份，本版不可修改。</p>
        </div>

        <!-- 类型（只读） -->
        <div>
          <span class="block text-xs font-medium text-slate-600">类型</span>
          <p class="mt-1 text-sm text-slate-800" data-v2-field="type">{{ typeLabel }}</p>
        </div>

        <!-- 状态 -->
        <div>
          <label class="block text-xs font-medium text-slate-600" :for="'asset-status-' + type">状态</label>
          <select
            :id="'asset-status-' + type"
            v-model="status"
            data-v2-field="status"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800"
          >
            <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>

        <!-- 预览图地址 -->
        <div>
          <label class="block text-xs font-medium text-slate-600" :for="'asset-thumb-' + type">预览图地址</label>
          <input
            :id="'asset-thumb-' + type"
            v-model="thumbnailUrl"
            type="text"
            data-v2-field="thumbnail_url"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800"
            placeholder="https://… 或 /storage/…（留空清除）"
          />
        </div>

        <!-- 说明 -->
        <div>
          <label class="block text-xs font-medium text-slate-600" :for="'asset-desc-' + type">说明</label>
          <textarea
            :id="'asset-desc-' + type"
            v-model="description"
            rows="3"
            data-v2-field="description"
            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800"
            placeholder="人物：外形 / 身份要点；场景：稳定母版说明（留空清除）"
          />
        </div>

        <!-- 最近使用（edit 只读） -->
        <div v-if="isEdit">
          <span class="block text-xs font-medium text-slate-600">最近使用</span>
          <p class="mt-1 text-sm text-slate-800" data-v2-field="last_used_at">
            {{ formatLastUsed(item?.last_used_at ?? null) }}
          </p>
          <p class="mt-1 text-[11px] text-slate-400">由系统在正式使用时记录，不可手填。</p>
        </div>
      </div>

      <p v-if="shownError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700" role="alert" data-v2-asset-error>
        {{ shownError }}
      </p>

      <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
          @click="emit('close')"
        >
          取消
        </button>
        <button
          type="button"
          class="rounded-md bg-slate-800 px-3 py-1.5 text-xs text-white hover:bg-slate-700 disabled:opacity-60"
          :disabled="saving"
          data-v2-asset-save
          @click="onSubmit"
        >
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </div>
    </div>
  </div>
</template>
