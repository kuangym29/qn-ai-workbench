<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { v2Api, type ContentItem, type ContentBranch, type BranchSelection } from '../../api/v2';
import { firstErrorMessage } from '../../api/errors';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import SourceTrail from '../../components/SourceTrail.vue';
import ContentBranchCard from '../../components/ContentBranchCard.vue';
import AddBranchDialog from '../../components/AddBranchDialog.vue';
const props = defineProps<{ projectId: string | number; itemId: string | number }>();
const item = ref<ContentItem | null>(null); const form = reactive({title: '', core_message: '', story_line: ''});
const loading = ref(true); const busy = ref(false); const error = ref(''); const saved = ref(false); const adding = ref(false); const branchError = ref('');
const stopping = ref<ContentBranch | null>(null);
const stopDialog = ref<HTMLDialogElement>();
async function requestStop(branch: ContentBranch) {
  error.value = '';
  stopping.value = branch;
  await nextTick();
  stopDialog.value?.showModal();
}
async function load() { try { item.value = await v2Api.item(props.projectId, props.itemId); form.title = item.value.title; form.core_message = item.value.core_message ?? ''; form.story_line = item.value.story_line ?? ''; } catch(e) { error.value = firstErrorMessage(e) ?? '加载失败'; } finally { loading.value = false; } }
async function save() { if (!item.value) return; busy.value = true; error.value = ''; saved.value = false; try { item.value = await v2Api.updateItem(props.projectId, item.value.id, {...form, core_message: form.core_message || null, story_line: form.story_line || null}); saved.value = true; } catch(e) { error.value = firstErrorMessage(e) ?? '保存失败'; } finally { busy.value = false; } }
function replaceBranch(branch: ContentBranch) { if (!item.value) return; const exists = item.value.content_branches.some(b => b.id === branch.id); item.value.content_branches = exists ? item.value.content_branches.map(b => b.id === branch.id ? branch : b) : [...item.value.content_branches, branch]; }
async function add(selection: BranchSelection) { if (!item.value) return; busy.value = true; branchError.value = ''; error.value = ''; try { replaceBranch(await v2Api.addBranch(props.projectId, item.value.id, selection)); adding.value = false; } catch(e) { const message = firstErrorMessage(e) ?? '添加失败'; branchError.value = message; if (!adding.value) error.value = message; } finally { busy.value = false; } }
async function stop(branch: ContentBranch) { if (!item.value) return; busy.value = true; error.value = ''; try { replaceBranch(await v2Api.stopBranch(props.projectId, item.value.id, branch.id)); stopping.value = null; } catch(e) { error.value = firstErrorMessage(e) ?? '停止失败'; } finally { busy.value = false; } }
onMounted(load);
</script>
<template><AdminLayout><PageHeader :title="item?.title ?? '内容详情'" description="先完善共享内容，再分别制作各渠道内容。" /><Link :href="`/projects/${projectId}/content`" class="mb-4 inline-block text-sm underline">返回内容库</Link><p v-if="error" role="alert" class="mb-4 text-red-700">{{ error }}</p><p v-if="loading" role="status">正在加载…</p><template v-if="item"><form class="space-y-4 rounded-lg border border-slate-200 bg-white p-5" @submit.prevent="save"><label class="block text-sm">内容标题<input v-model="form.title" required maxlength="200" class="mt-1 block w-full rounded border border-slate-300 p-2" @input="saved = false"></label><label class="block text-sm">核心表达<textarea v-model="form.core_message" rows="3" class="mt-1 block w-full rounded border border-slate-300 p-2" @input="saved = false" /></label><label class="block text-sm">故事线<textarea v-model="form.story_line" rows="5" class="mt-1 block w-full rounded border border-slate-300 p-2" @input="saved = false" /></label><SourceTrail :inspiration="item.topic?.inspiration" :topic="item.topic" /><button :disabled="busy || !form.title.trim()" class="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{{ busy ? '保存中…' : '保存内容' }}</button><span v-if="saved" role="status" class="ml-3 text-sm text-emerald-700">已保存</span></form><div class="mb-3 mt-6 flex items-center justify-between"><h2 class="font-semibold">制作渠道</h2><button type="button" :disabled="busy" class="text-sm underline" @click="adding = true; branchError = ''">添加制作渠道</button></div><div class="grid gap-3 md:grid-cols-2"><ContentBranchCard v-for="b in item.content_branches" :key="b.id" :branch="b" :busy="busy" :editor-href="b.format === 'image_post' ? `/projects/${projectId}/content/${itemId}/branches/${b.id}/image-post` : undefined" @stop="requestStop(b)" @resume="add({channel: b.channel, format: b.format})" /></div><p v-if="!item.content_branches.length" class="text-sm text-slate-500">添加一个制作渠道开始创作。</p><AddBranchDialog v-if="adding" :branches="item.content_branches" :busy="busy" :error="branchError" @close="adding = false" @submit="add" /><dialog v-if="stopping" ref="stopDialog" aria-labelledby="stop-title" class="w-full max-w-lg rounded-xl p-6 backdrop:bg-black/40" @cancel.prevent="!busy && (stopping = null)">
  <h2 id="stop-title" class="mb-4 text-lg font-semibold">停止制作</h2>
  <p>停止“{{ stopping.channel_label }} · {{ stopping.format_label }}”的制作？历史记录会保留，之后可以恢复。</p>
  <p v-if="error" role="alert" class="mt-3 text-sm text-red-700">{{ error }}</p>
  <div class="mt-6 flex justify-end gap-3">
    <button type="button" :disabled="busy" @click="stopping = null">取消</button>
    <button type="button" :disabled="busy" class="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50" @click="stop(stopping)">{{ busy ? '正在停止…' : '确认停止' }}</button>
  </div>
</dialog></template></AdminLayout></template>
