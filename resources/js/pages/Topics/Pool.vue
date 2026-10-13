<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { v2Api, type Topic, type Inspiration, type BranchSelection } from '../../api/v2';
import { columnsApi } from '../../api/columns';
import type { Column } from '../../api/types';
import { firstErrorMessage } from '../../api/errors';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import SourceTrail from '../../components/SourceTrail.vue';
import StartCreationDialog from '../../components/StartCreationDialog.vue';
const props = defineProps<{ projectId: string | number }>();
const topics = ref<Topic[]>([]); const columns = ref<Column[]>([]); const inspiration = ref<Inspiration | null>(null);
const filter = ref<'pending' | 'parked'>('pending'); const backupOnly = ref(false); const title = ref(''); const description = ref(''); const column = ref('');
const busy = ref(false); const loading = ref(true); const error = ref(''); const startError = ref(''); const starting = ref<Topic | null>(null);
const visible = computed(() => topics.value.filter(t => t.status === filter.value && (!backupOnly.value || t.is_backup)));
async function load() { try { const [ts, cs] = await Promise.all([v2Api.topics(props.projectId), columnsApi.list(props.projectId)]); topics.value = ts; columns.value = cs; const id = Number(new URLSearchParams(window.location.search).get('inspiration')); if (id) { inspiration.value = (await v2Api.inspirations(props.projectId)).find(i => i.id === id) ?? null; if (inspiration.value) { title.value = inspiration.value.body.split('\n')[0].slice(0, 200); description.value = inspiration.value.body; } } } catch(e) { error.value = firstErrorMessage(e) ?? '加载失败'; } finally { loading.value = false; } }
async function create() { busy.value = true; error.value = ''; try { topics.value.unshift(await v2Api.createTopic(props.projectId, {title: title.value, description: description.value || null, content_column_id: Number(column.value), inspiration_id: inspiration.value?.id ?? null})); title.value = ''; description.value = ''; inspiration.value = null; router.replace({url: '/projects/' + props.projectId + '/topics', preserveState: true, preserveScroll: true}); filter.value = 'pending'; backupOnly.value = false; } catch(e) { error.value = firstErrorMessage(e) ?? '保存失败'; } finally { busy.value = false; } }
async function update(topic: Topic, data: Partial<Pick<Topic, 'status' | 'is_backup'>>) { busy.value = true; error.value = ''; try { const updated = await v2Api.updateTopic(props.projectId, topic.id, data); topics.value = topics.value.map(t => t.id === updated.id ? updated : t); } catch(e) { error.value = firstErrorMessage(e) ?? '保存失败'; } finally { busy.value = false; } }
async function start(branches: BranchSelection[]) { if (!starting.value) return; busy.value = true; startError.value = ''; try { const item = await v2Api.start(props.projectId, starting.value.id, branches); router.visit(`/projects/${props.projectId}/content/${item.id}`); } catch(e) { if (axios.isAxiosError(e) && e.response?.status === 409 && e.response.data?.content_item_id) { router.visit(`/projects/${props.projectId}/content/${Number(e.response.data.content_item_id)}`); } else { startError.value = firstErrorMessage(e) ?? '创建失败'; } } finally { busy.value = false; } }
onMounted(load);
</script>
<template><AdminLayout><PageHeader title="选题" description="整理值得创作的想法，准备好后选择制作渠道。" />
  <form class="mb-6 space-y-3 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="create"><h2 class="font-medium">{{ inspiration ? '从灵感生成选题' : '直接创建选题' }}</h2><SourceTrail :inspiration="inspiration" />
    <label class="block text-sm">选题标题<input v-model="title" required maxlength="200" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
    <label class="block text-sm">说明（可选）<textarea v-model="description" rows="2" class="mt-1 block w-full rounded border border-slate-300 p-2" /></label>
    <label class="block text-sm">所属栏目<select v-model="column" required class="ml-3 rounded border border-slate-300 p-2"><option value="" disabled>请选择栏目</option><option v-for="c in columns" :key="c.id" :value="String(c.id)">{{ c.name }}</option></select></label>
    <p v-if="!loading && !columns.length" class="text-sm text-slate-500">请先在项目 / 栏目设置中创建栏目。</p>
    <button :disabled="busy || !title.trim() || !column" class="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">保存选题</button><button v-if="inspiration" type="button" class="ml-3 text-sm underline" @click="inspiration = null">取消关联灵感</button>
  </form>
  <p v-if="error" role="alert" class="mb-4 text-red-700">{{ error }}</p>
  <div class="mb-4 flex gap-4"><button v-for="s in (['pending','parked'] as const)" :key="s" type="button" :aria-pressed="filter === s" :class="filter === s ? 'font-semibold underline' : 'text-slate-500'" @click="filter = s">{{ s === 'pending' ? '待选' : '暂存' }}</button><label class="text-sm"><input v-model="backupOnly" type="checkbox"> 只看备用</label></div>
  <p v-if="loading" role="status">正在加载…</p><p v-else-if="!visible.length" class="text-slate-500">当前没有选题。</p>
  <article v-for="t in visible" :key="t.id" class="mb-3 rounded-lg border border-slate-200 bg-white p-4"><div class="flex items-center gap-3"><button type="button" :disabled="busy" :aria-pressed="t.is_backup" :aria-label="t.is_backup ? '取消备用' : '标为备用'" @click="update(t, {is_backup: !t.is_backup})">{{ t.is_backup ? '★' : '☆' }}</button><h2 class="font-medium">{{ t.title }}</h2></div><p v-if="t.description" class="mt-2 whitespace-pre-wrap text-sm text-slate-600">{{ t.description }}</p><SourceTrail :inspiration="t.inspiration" /><div class="mt-3 flex gap-4 text-sm"><button v-if="!t.has_content_item" type="button" :disabled="busy" class="underline" @click="starting = t; startError = ''">开始创作</button><button v-else type="button" class="underline" @click="router.visit(`/projects/${projectId}/content/${t.content_item_id}`)">查看内容</button><button type="button" :disabled="busy" @click="update(t, {status: t.status === 'pending' ? 'parked' : 'pending'})">{{ t.status === 'pending' ? '移入暂存' : '移回待选' }}</button></div></article>
  <StartCreationDialog v-if="starting" :busy="busy" :error="startError" @close="starting = null" @submit="start" />
</AdminLayout></template>
