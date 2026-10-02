<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type { Column, Project, Topic } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { is404, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import EmptyState from '../../components/EmptyState.vue';

const props = defineProps<{ projectId: string; columnId: string }>();

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const errorMsg = ref('');
const project = ref<Project | null>(null);
const column = ref<Column | null>(null);
const topics = ref<Topic[]>([]);

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    // Breadcrumb context: the project name comes from the list (there is no per-project
    // show endpoint) and the column comes from the existing formal column GET endpoint.
    const list = await projectsApi.list();
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = await columnsApi.get(props.projectId, props.columnId);
    // Topics are strictly scoped to Project → Column. A 404 means the scope does not match
    // the current server session — fall back, never tamper with the session.
    topics.value = await topicsApi.list(props.projectId, props.columnId);
    status.value = 'ready';
  } catch (e) {
    if (is404(e)) {
      toast.error('该项目或栏目不在当前会话作用域内，已返回项目列表');
      router.visit('/projects');
      return;
    }
    errorMsg.value = firstErrorMessage(e) ?? '加载失败';
    status.value = 'error';
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <Breadcrumb
      :items="[
        { label: project?.name ?? '项目', href: '/projects' },
        { label: column?.name ?? '小栏目', href: `/projects/${projectId}/columns` },
        { label: '选题' },
      ]"
    />

    <PageHeader
      :title="column ? `选题 · ${column.name}` : '选题'"
      description="选题归属于当前栏目，是后续篇目与文案生产的组织单元。"
    >
      <template #actions>
        <Link
          :href="`/projects/${projectId}/columns`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回栏目
        </Link>
        <Link
          :href="`/projects/${projectId}/columns/${columnId}/topics/create`"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新增选题
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" />
    <ErrorState v-else-if="status === 'error'" :message="errorMsg" />

    <EmptyState
      v-else-if="status === 'ready' && topics.length === 0"
      title="还没有选题"
      description="在当前栏目下创建第一个选题，用于组织篇目与文案。"
    >
      <template #action>
        <Link
          :href="`/projects/${projectId}/columns/${columnId}/topics/create`"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新增选题
        </Link>
      </template>
    </EmptyState>

    <div v-else class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3 font-medium">选题标题</th>
            <th class="px-4 py-3 font-medium">说明</th>
            <th class="px-4 py-3 text-right font-medium">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="t in topics" :key="t.id" class="hover:bg-slate-50">
            <td class="px-4 py-3 font-medium text-slate-900">{{ t.title }}</td>
            <td class="px-4 py-3 text-slate-600">{{ t.description || '—' }}</td>
            <td class="px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-2">
                <Link
                  :href="`/projects/${projectId}/columns/${columnId}/topics/${t.id}/items`"
                  class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
                >
                  进入篇目
                </Link>
                <Link
                  :href="`/projects/${projectId}/columns/${columnId}/topics/${t.id}/edit`"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  编辑
                </Link>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>
