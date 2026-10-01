<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type { Project } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { projectContext, selectProject } from '../../stores/projectContext';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import EmptyState from '../../components/EmptyState.vue';
import Badge from '../../components/Badge.vue';

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const errorMsg = ref('');
const projects = ref<Project[]>([]);

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    projects.value = await projectsApi.list();
    status.value = 'ready';
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '加载失败';
    status.value = 'error';
  }
}

// "项目上下文优先"：选择项目 = 先调服务器 select，成功后再进入其小栏目。
async function enter(p: Project): Promise<void> {
  try {
    await selectProject(p.id);
    toast.success(`已切换到项目：${p.name}`);
    router.visit(`/projects/${p.id}/columns`);
  } catch {
    toast.error('进入项目失败，请重试');
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <PageHeader
      title="项目"
      description="QN AI 内容工作台以项目为最高数据隔离边界。先选择一个项目，再进入其小栏目与后续内容。"
    >
      <template #actions>
        <Link
          href="/projects/create"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新建项目
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" />
    <ErrorState v-else-if="status === 'error'" :message="errorMsg" />

    <EmptyState
      v-else-if="status === 'ready' && projects.length === 0"
      title="还没有项目"
      description="创建第一个项目，作为后续栏目与内容的最高隔离边界。"
    >
      <template #action>
        <Link
          href="/projects/create"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          新建项目
        </Link>
      </template>
    </EmptyState>

    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="p in projects"
        :key="p.id"
        class="flex flex-col rounded-lg border border-slate-200 bg-white p-4"
      >
        <div class="flex items-start justify-between gap-2">
          <h2 class="text-sm font-semibold text-slate-900">{{ p.name }}</h2>
          <Badge v-if="projectContext.current?.id === p.id" variant="active" label="当前" />
        </div>
        <p class="mt-1 text-xs text-slate-400">/{{ p.slug }}</p>
        <p class="mt-2 line-clamp-2 flex-1 text-sm text-slate-600">
          {{ p.description || '暂无描述' }}
        </p>
        <div class="mt-4 flex items-center gap-2">
          <button
            type="button"
            class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
            @click="enter(p)"
          >
            进入
          </button>
          <Link
            :href="`/projects/${p.id}/edit`"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            编辑
          </Link>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
