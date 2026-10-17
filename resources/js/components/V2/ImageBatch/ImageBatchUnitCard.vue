<script setup lang="ts">
// V2 图片批次 —— 页面单元卡（系统自动拆页，用户查看 / 轻量修正 / 选择参与本次生成）。
// 原则：每页不需要重新完整录入；卡片以「最终文字弱展示 + 画面证据 + 人物 + 场景」为主，
// 制作备注 / 特殊要求弱化；单页状态轻量；来源内容变化给轻量提醒。
//
// P5-I03：人物 / 场景从只读展示升级为轻量编辑。点击「人物 / 场景」打开一个折叠区
// （不是弹层、不占额外层），人物多选、场景单选，默认卡片仍然简洁。
// 引用只能来自用户显式选择：没有可选资产时不猜测、不自动创建，只引导去复用索引建立。
import { ref, watch } from 'vue';
import type { ImageBatchUnit } from '../../../types/v2/imageBatch/imageBatch';
import type { AssetOption } from '../../../composables/v2/imageBatch/assetOptions';
import { pageLabel, pageTypeLabel, unitStatusLabel } from '../../../composables/v2/imageBatch/imageBatchCore';

const props = defineProps<{
  unit: ImageBatchUnit;
  selected: boolean;
  disabled?: boolean;
  /** 当前 Project 的可选人物 / 场景（来自正式 GET /reusable-assets）。 */
  characterOptions: AssetOption[];
  sceneOptions: AssetOption[];
  assetOptionsLoading?: boolean;
  /** 复用索引管理页链接（无可选资产时引导用户去建立）。 */
  assetManageUrl?: string;
}>();

const emit = defineEmits<{
  toggle: [unit: ImageBatchUnit];
  update: [unit: ImageBatchUnit];
  updateAssets: [unit: ImageBatchUnit, patch: Pick<ImageBatchUnit, 'character_asset_ids' | 'scene_asset_id'>];
  viewChange: [unit: ImageBatchUnit];
  keepCurrent: [unit: ImageBatchUnit];
  regenerate: [unit: ImageBatchUnit];
}>();

const statusTone: Record<string, string> = {
  pending: 'bg-slate-100 text-slate-600',
  generated: 'bg-emerald-100 text-emerald-700',
  needs_revision: 'bg-amber-100 text-amber-700',
  approved: 'bg-blue-100 text-blue-700',
};

// 轻量修正：输入即更新 Draft，让执行动作可以直接自动保存；取消恢复进入编辑前的值。
const editing = ref(false);
const draft = ref('');
const original = ref('');
watch(() => props.unit, () => { editing.value = false; });
function startEdit(): void {
  if (props.disabled) return;
  draft.value = props.unit.special_requirement ?? '';
  original.value = draft.value;
  editing.value = true;
}
function saveEdit(): void {
  if (props.disabled) return;
  emit('update', { ...props.unit, special_requirement: draft.value.trim() || null });
  editing.value = false;
}
function cancelEdit(): void {
  if (props.disabled) return;
  emit('update', { ...props.unit, special_requirement: original.value || null });
  editing.value = false;
}

// ── P5-I03：人物 / 场景显式引用编辑（折叠区） ──────────────────────────────
const assetsOpen = ref(false);
function toggleAssets(): void {
  if (props.disabled) return;
  assetsOpen.value = !assetsOpen.value;
}
function nameOf(options: AssetOption[], id: number | null): string | null {
  if (id === null) return null;
  return options.find((option) => option.id === id)?.name ?? null;
}
const characterNames = (): string[] =>
  props.unit.character_asset_ids
    .map((id) => nameOf(props.characterOptions, id))
    .filter((name): name is string => name !== null);
const sceneName = (): string | null => nameOf(props.sceneOptions, props.unit.scene_asset_id);

function toggleCharacter(id: number): void {
  if (props.disabled) return;
  const next = props.unit.character_asset_ids.includes(id)
    ? props.unit.character_asset_ids.filter((kept) => kept !== id)
    : [...props.unit.character_asset_ids, id];
  emit('updateAssets', props.unit, { character_asset_ids: next, scene_asset_id: props.unit.scene_asset_id });
}
function selectScene(id: number): void {
  if (props.disabled) return;
  emit('updateAssets', props.unit, { character_asset_ids: props.unit.character_asset_ids, scene_asset_id: id });
}
function clearCharacters(): void {
  if (props.disabled) return;
  emit('updateAssets', props.unit, { character_asset_ids: [], scene_asset_id: props.unit.scene_asset_id });
}
function clearScene(): void {
  if (props.disabled) return;
  emit('updateAssets', props.unit, { character_asset_ids: props.unit.character_asset_ids, scene_asset_id: null });
}
</script>

<template>
  <article
    class="rounded-lg border border-slate-200 bg-white p-4"
    :data-image-batch-unit="`P${unit.page_no}`"
  >
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="flex min-w-0 items-center gap-2">
        <label class="flex items-center gap-1.5 text-sm">
          <input
            type="checkbox"
            class="h-4 w-4 rounded border-slate-300"
            :checked="selected"
            :disabled="disabled"
            @change="emit('toggle', unit)"
          />
          <span class="font-semibold text-slate-900">{{ pageLabel(unit.page_no) }}</span>
        </label>
        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">
          {{ pageTypeLabel(unit.page_type) }}
        </span>
        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusTone[unit.status] ?? statusTone.pending">
          {{ unitStatusLabel(unit.status) }}
        </span>
      </div>
      <button
        v-if="!editing"
        type="button"
        class="text-xs text-slate-400 hover:text-slate-600"
        :disabled="disabled"
        @click="startEdit"
      >
        轻量修正
      </button>
    </div>

    <!-- 轻量修正：特殊要求 inline 编辑 -->
    <div v-if="editing" class="mt-2 rounded-md border border-slate-200 bg-slate-50 p-2">
      <label class="block text-xs text-slate-500" :for="'batch-special-' + unit.id">特殊要求（仅此页）</label>
      <textarea
        :id="'batch-special-' + unit.id"
        v-model="draft"
        :disabled="disabled"
        @input="emit('update', { ...unit, special_requirement: ($event.target as HTMLTextAreaElement).value || null })"
        rows="2"
        class="mt-1 w-full resize-y rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-800 focus:border-slate-400 focus:outline-none"
        placeholder="本页生图特殊要求（可选）"
      />
      <div class="mt-2 flex gap-2">
        <button
          type="button"
          class="rounded-md bg-slate-900 px-3 py-1 text-xs font-medium text-white hover:bg-slate-800"
          @click="saveEdit"
          :disabled="disabled"
        >
          保存
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-100"
          @click="cancelEdit"
          :disabled="disabled"
        >
          取消
        </button>
      </div>
    </div>

    <!-- 来源内容变化提醒（轻量，不显示技术 Revision 编号） -->
    <div
      v-if="unit.source_changed"
      class="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
      role="status"
    >
      <span>⚠ 来源内容后来有修改，建议检查本页图片是否受影响。</span>
      <button type="button" :disabled="disabled" class="underline hover:text-amber-900" @click="emit('viewChange', unit)">查看修改</button>
      <button type="button" :disabled="disabled" class="underline hover:text-amber-900" @click="emit('keepCurrent', unit)">继续沿用</button>
      <button type="button" :disabled="disabled" class="underline hover:text-amber-900" @click="emit('regenerate', unit)">重新生成</button>
    </div>
    <p v-if="unit.source_changed && unit.source_change_summary" class="mt-1 text-xs text-amber-700">
      {{ unit.source_change_summary }}
    </p>

    <!-- 最终文字（弱展示） -->
    <p class="mt-3 line-clamp-2 text-sm text-slate-700">{{ unit.final_copy ?? '（无最终文字）' }}</p>

    <!-- 画面证据 -->
    <p class="mt-2 text-xs text-slate-500">
      <span class="text-slate-400">画面证据：</span>{{ unit.visual_evidence ?? '—' }}
    </p>

    <!-- 人物 / 场景 / 特殊要求（人物与场景为可点击的显式引用入口） -->
    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded px-1 py-0.5 text-left hover:bg-slate-100 hover:text-slate-700"
        :disabled="disabled"
        data-v2-asset-ref-trigger="characters"
        :aria-expanded="assetsOpen"
        @click="toggleAssets"
      >
        <span class="text-slate-400">人物：</span>
        <span v-if="characterNames().length">{{ characterNames().join('、') }}</span>
        <span v-else>—</span>
      </button>
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded px-1 py-0.5 text-left hover:bg-slate-100 hover:text-slate-700"
        :disabled="disabled"
        data-v2-asset-ref-trigger="scene"
        :aria-expanded="assetsOpen"
        @click="toggleAssets"
      >
        <span class="text-slate-400">场景：</span>
        <span v-if="sceneName()">{{ sceneName() }}</span>
        <span v-else>—</span>
      </button>
      <span v-if="unit.special_requirement" class="text-slate-600">
        <span class="text-slate-400">特殊要求：</span>{{ unit.special_requirement }}
      </span>
    </div>

    <!-- 显式引用编辑折叠区：人物多选 + 场景单选（不引入第二套查询，选项来自正式 GET） -->
    <div
      v-if="assetsOpen"
      class="mt-2 rounded-md border border-slate-200 bg-slate-50 p-3"
      data-v2-asset-ref-editor
    >
      <p class="text-xs text-slate-500">
        明确这一页使用了哪些人物、哪个场景。只登记你显式选择的引用，未选择即不引用。
      </p>

      <div class="mt-2">
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs font-medium text-slate-700">人物（可多选）</span>
          <button
            v-if="unit.character_asset_ids.length"
            type="button"
            class="text-xs text-slate-400 underline hover:text-slate-600"
            :disabled="disabled"
            data-v2-asset-clear="characters"
            @click="clearCharacters"
          >
            清空人物
          </button>
        </div>
        <div v-if="characterOptions.length" class="mt-1 flex flex-col gap-1" data-v2-asset-option-list="characters">
          <label
            v-for="option in characterOptions"
            :key="option.id"
            class="flex items-center gap-2 rounded px-1 py-1 text-xs text-slate-700 hover:bg-white"
          >
            <input
              type="checkbox"
              class="h-4 w-4 rounded border-slate-300"
              :checked="unit.character_asset_ids.includes(option.id)"
              :disabled="disabled"
              :data-v2-asset-option="`character-${option.id}`"
              @change="toggleCharacter(option.id)"
            />
            <span class="min-w-0 flex-1 truncate">{{ option.name }}</span>
            <span class="shrink-0 text-slate-400">{{ option.statusLabel }}</span>
          </label>
        </div>
        <p v-else-if="assetOptionsLoading" class="mt-1 text-xs text-slate-400">正在加载可选人物…</p>
        <p v-else class="mt-1 text-xs text-slate-400">
          暂无人物，可先到<a
            v-if="assetManageUrl"
            :href="assetManageUrl"
            class="text-slate-600 underline"
            data-v2-asset-manage-link
          >人物 / 场景复用索引</a><span v-else>人物 / 场景复用索引</span>中建立。
        </p>
      </div>

      <div class="mt-3">
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs font-medium text-slate-700">场景（单选）</span>
          <button
            v-if="unit.scene_asset_id !== null"
            type="button"
            class="text-xs text-slate-400 underline hover:text-slate-600"
            :disabled="disabled"
            data-v2-asset-clear="scene"
            @click="clearScene"
          >
            清空场景
          </button>
        </div>
        <div v-if="sceneOptions.length" class="mt-1 flex flex-col gap-1" data-v2-asset-option-list="scene">
          <label
            v-for="option in sceneOptions"
            :key="option.id"
            class="flex items-center gap-2 rounded px-1 py-1 text-xs text-slate-700 hover:bg-white"
          >
            <input
              type="radio"
              class="h-4 w-4 border-slate-300"
              :checked="unit.scene_asset_id === option.id"
              :disabled="disabled"
              :name="`batch-scene-${unit.id}`"
              :data-v2-asset-option="`scene-${option.id}`"
              @change="selectScene(option.id)"
            />
            <span class="min-w-0 flex-1 truncate">{{ option.name }}</span>
            <span class="shrink-0 text-slate-400">{{ option.statusLabel }}</span>
          </label>
        </div>
        <p v-else-if="assetOptionsLoading" class="mt-1 text-xs text-slate-400">正在加载可选场景…</p>
        <p v-else class="mt-1 text-xs text-slate-400">
          暂无场景，可先到<a
            v-if="assetManageUrl"
            :href="assetManageUrl"
            class="text-slate-600 underline"
            data-v2-asset-manage-link
          >人物 / 场景复用索引</a><span v-else>人物 / 场景复用索引</span>中建立。
        </p>
      </div>
    </div>
  </article>
</template>
