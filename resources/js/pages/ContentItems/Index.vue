<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { COPY_STATUS_LABELS } from '../../api/types';
import type { Column, ContentItem, CopyStatus, Project, Topic } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { contentItemsApi } from '../../api/contentItems';
import { is404, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import EmptyState from '../../components/EmptyState.vue';
import Badge from '../../components/Badge.vue';

const props = defineProps<{ projectId: string; columnId: string; topicId: string }>();

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const errorMsg = ref('');
const project = ref<Project | null>(null);
const column = ref<Column | null>(null);
const topic = ref<Topic | null>(null);
const items = ref<ContentItem[]>([]);

// Copy status drives the badge colour; the label carries the Chinese wording while the
// underlying value always stays the English formal value.
function statusVariant(value: CopyStatus): 'default' | 'muted' | 'active' | 'planning' {
  if (value === 'confirmed') return 'active';
  if (value === 'editing' || value === 'pending_confirmation') return 'planning';
  return 'default';
}

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    // Breadcrumb context: project from the list, column and topic via existing GET APIs.
    const list = await projectsApi.list();
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = await columnsApi.get(props.projectId, props.columnId);
    topic.value = await topicsApi.get(props.projectId, props.columnId, props.topicId);
    // Items are strictly scoped to Project → Column → Topic. A 404 means the scope does
    // not match the current server session — fall back, never tamper with the session.
    items.value = await contentItemsApi.list(props.projectId, props.columnId, props.topicId);
    status.value = 'ready';
  } catch (e) {
    if (is404(e)) {
      toast.error('该项目或选题不在当前会话作用域内，已返回项目列表');
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
        {
          label: topic?.title ?? '选题',
          href: `/projects/${projectId}/columns/${columnId}/topics`,
        },
        { label: '篇目' },
      ]"
    />

    <PageHeader
      :title="topic ? `篇目 · ${topic.title}` : '篇目'"
      description="篇目归属于当前选题，是文案生产与状态跟踪的基本单元。"
    >
      <template #actions>
        <Link
          :href="`/projects/${projectId}/columns/${columnId}/topics`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回选题
        </Link>
        <Link
          :href="`/projects/${projectId}/columns/${columnId}/topics/${topicId}/items/create`"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新增篇目
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" />
    <ErrorState v-else-if="status === 'error'" :message="errorMsg" />

    <EmptyState
      v-else-if="status === 'ready' && items.length === 0"
      title="还没有篇目"
      description="在当前选题下创建第一个篇目，用于跟踪文案状态。"
    >
      <template #action>
        <Link
          :href="`/projects/${projectId}/columns/${columnId}/topics/${topicId}/items/create`"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新增篇目
        </Link>
      </template>
    </EmptyState>

    <div v-else class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3 font-medium">标题</th>
            <th class="px-4 py-3 font-medium">文案状态</th>
            <th class="px-4 py-3 text-right font-medium">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50">
            <td class="px-4 py-3 font-medium text-slate-900">{{ item.title }}</td>
            <td class="px-4 py-3">
              <Badge
                :variant="statusVariant(item.copy_status)"
                :label="COPY_STATUS_LABELS[item.copy_status]"
              />
            </td>
            <td class="px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-2">
                <Link
                  :href="`/projects/${projectId}/columns/${columnId}/topics/${topicId}/items/${item.id}/copy`"
                  class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
                >
                  编辑文案
                </Link>
                <Link
                  :href="`/projects/${projectId}/columns/${columnId}/topics/${topicId}/items/${item.id}/edit`"
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
