<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { BRANCH_OPTIONS, type ContentBranch, type BranchSelection } from '../api/v2';
const props = defineProps<{ branches: ContentBranch[]; busy?: boolean; error?: string }>();
const emit = defineEmits<{ close: []; submit: [selection: BranchSelection] }>();
const dialog = ref<HTMLDialogElement>();
const selected = ref('');
const available = computed(() => BRANCH_OPTIONS.filter(o => !props.branches.some(b => b.channel === o.channel && b.format === o.format && !b.stopped_at)));
function submit() { const option = available.value.find(o => o.format === selected.value); if (option) emit('submit', {channel: option.channel, format: option.format}); }
onMounted(() => dialog.value?.showModal());
</script>
<template>
  <dialog ref="dialog" aria-labelledby="add-title" class="w-full max-w-lg rounded-xl p-6 backdrop:bg-black/40" @cancel.prevent="!busy && emit('close')">
    <h2 id="add-title" class="mb-4 text-lg font-semibold">添加制作渠道</h2>
    <form @submit.prevent="submit">
      <label v-for="option in available" :key="option.format" class="my-3 flex items-center gap-2"><input v-model="selected" type="radio" name="branch" :value="option.format" :disabled="busy">{{ option.label }}<span v-if="branches.some(b => b.format === option.format && b.stopped_at)" class="text-xs text-slate-500">恢复已有记录</span></label>
      <p v-if="!available.length" class="text-sm text-slate-500">已添加所有可用内容形式。</p>
      <label class="my-3 flex items-center gap-2 text-slate-400"><input type="radio" disabled>小红书（预留）</label>
      <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
      <div class="mt-6 flex justify-end gap-3"><button type="button" :disabled="busy" @click="emit('close')">取消</button><button class="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50" :disabled="busy || !selected">{{ busy ? '正在保存…' : '添加 / 恢复' }}</button></div>
    </form>
  </dialog>
</template>
