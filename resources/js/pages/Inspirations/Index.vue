<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { v2Api, type Inspiration } from '../../api/v2';
import { firstErrorMessage } from '../../api/errors';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
const props = defineProps<{ projectId: string | number }>();
const inspirations = ref<Inspiration[]>([]);
const body = ref(''); const note = ref(''); const error = ref(''); const busy = ref(false); const loading = ref(true);
async function load() { try { inspirations.value = await v2Api.inspirations(props.projectId); } catch(e) { error.value = firstErrorMessage(e) ?? '加载失败'; } finally { loading.value = false; } }
async function save() { busy.value = true; error.value = ''; try { const item = await v2Api.createInspiration(props.projectId, {body: body.value, note: note.value || null}); inspirations.value.unshift(item); body.value = ''; note.value = ''; } catch(e) { error.value = firstErrorMessage(e) ?? '保存失败'; } finally { busy.value = false; } }
onMounted(load);
</script>
<template><AdminLayout><PageHeader title="灵感" description="先把想法记下来，想创作时再生成选题。" />
  <form class="mb-6 space-y-3 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="save">
    <label class="block text-sm">灵感内容<textarea v-model="body" required rows="3" class="mt-1 block w-full rounded border border-slate-300 p-2" /></label>
    <label class="block text-sm">补充备注（可选）<textarea v-model="note" rows="2" class="mt-1 block w-full rounded border border-slate-300 p-2" /></label>
    <button :disabled="busy || !body.trim()" class="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{{ busy ? '保存中…' : '记下灵感' }}</button>
  </form>
  <p v-if="error" role="alert" class="mb-4 text-red-700">{{ error }}</p><p v-if="loading" role="status">正在加载…</p><p v-else-if="!inspirations.length" class="text-slate-500">还没有灵感，从上方记下第一个想法。</p>
  <article v-for="item in inspirations" :key="item.id" class="mb-3 rounded-lg border border-slate-200 bg-white p-4"><p class="whitespace-pre-wrap">{{ item.body }}</p><p v-if="item.note" class="mt-2 whitespace-pre-wrap text-sm text-slate-500">{{ item.note }}</p><button type="button" class="mt-3 text-sm underline" @click="router.visit(`/projects/${projectId}/topics?inspiration=${item.id}`)">生成选题</button></article>
</AdminLayout></template>
