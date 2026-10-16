<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { BRANCH_OPTIONS, type BranchSelection } from '../api/v2';
defineProps<{ busy?: boolean; error?: string }>();
const emit = defineEmits<{ close: []; submit: [branches: BranchSelection[]] }>();
const dialog = ref<HTMLDialogElement>();
const selected = ref(['image_post', 'video']);
onMounted(() => dialog.value?.showModal());
</script>
<template>
  <dialog ref="dialog" aria-labelledby="start-title" class="w-full max-w-lg rounded-xl p-6 backdrop:bg-black/40" @cancel.prevent="!busy && emit('close')">
    <h2 id="start-title" class="mb-4 text-lg font-semibold">开始创作</h2>
    <form @submit.prevent="emit('submit', BRANCH_OPTIONS.filter(o => selected.includes(o.format)).map(({channel, format}) => ({channel, format})))">
      <p class="mb-3 text-sm text-slate-500">选择本次制作的渠道与内容形式，可在之后继续添加。</p>
      <label v-for="option in BRANCH_OPTIONS" :key="option.format" class="my-3 flex items-center gap-2"><input v-model="selected" type="checkbox" :value="option.format" :disabled="busy">{{ option.label }}</label>
      <label class="my-3 flex items-center gap-2 text-slate-400"><input type="checkbox" disabled>小红书（预留）</label>
      <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
      <div class="mt-6 flex justify-end gap-3"><button type="button" :disabled="busy" @click="emit('close')">取消</button><button class="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50" :disabled="busy || !selected.length">{{ busy ? '正在创建…' : '开始创作' }}</button></div>
    </form>
  </dialog>
</template>
