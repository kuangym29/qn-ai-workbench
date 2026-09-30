<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { Column, Project } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import EmptyState from '../../components/EmptyState.vue';

const props = defineProps<{ projectId: string }>();

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const errorMsg = ref('');
const project = ref<Project | null>(null);
const columns = ref<Column[]>([]);

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    const [p, cols] = await Promise.all([
      projectsApi.get(props.projectId),
      columnsApi.list(props.projectId),
    ]);
    project.value = p ?? null;
    columns.value = cols;
    status.value = 'ready';
    if (!p) toast.info('当前项目可能已被移除，请重新选择项目');
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '加载失败';
    status.value = 'error';
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <PageHeader
      :title="project ? `小栏目 · ${project.name}` : '小栏目'"
      description="小栏目归属于当前项目，是后续选题与内容生产的组织单元。"
    >
      <template #actions>
        <Link
          href="/projects"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回项目
        </Link>
        <Link
          :href="`/projects/${projectId}/columns/create`"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新建小栏目
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" />
    <ErrorState v-else-if="status === 'error'" :message="errorMsg" />

    <EmptyState
      v-else-if="status === 'ready' && columns.length === 0"
      title="还没有小栏目"
      description="在当前项目下创建第一个小栏目，用于组织选题与内容。"
    >
      <template #action>
        <Link
          :href="`/projects/${projectId}/columns/create`"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新建小栏目
        </Link>
      </template>
    </EmptyState>

    <div v-else class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3 font-medium">名称</th>
            <th class="px-4 py-3 font-medium">Slug</th>
            <th class="px-4 py-3 font-medium">排序</th>
            <th class="px-4 py-3 font-medium">描述</th>
            <th class="px-4 py-3 text-right font-medium">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="c in columns" :key="c.id" class="hover:bg-slate-50">
            <td class="px-4 py-3 font-medium text-slate-900">{{ c.name }}</td>
            <td class="px-4 py-3 text-slate-500">/{{ c.slug }}</td>
            <td class="px-4 py-3 text-slate-500">{{ c.sort_order }}</td>
            <td class="px-4 py-3 text-slate-600">{{ c.description || '—' }}</td>
            <td class="px-4 py-3 text-right">
              <Link
                :href="`/projects/${projectId}/columns/${c.id}/edit`"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                编辑
              </Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>
