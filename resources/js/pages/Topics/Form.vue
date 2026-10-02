<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import type { Column, Project, TopicInput } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { is404, extractFieldErrors, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';

const page = usePage();
const props = defineProps<{ projectId: string; columnId: string; id?: string }>();
const mode = ((page.props.mode as string) ?? 'create') as 'create' | 'edit';

const loading = ref(true);
const loadError = ref('');
const submitting = ref(false);
const project = ref<Project | null>(null);
const column = ref<Column | null>(null);

const form = ref<{ title: string; description: string }>({ title: '', description: '' });
const errors = ref<{ title?: string }>({});

const topicsUrl = `/projects/${props.projectId}/columns/${props.columnId}/topics`;

async function load(): Promise<void> {
  loading.value = true;
  try {
    // Breadcrumb context only — both come from existing formal GET endpoints.
    const list = await projectsApi.list();
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = await columnsApi.get(props.projectId, props.columnId);

    if (mode !== 'edit' || !props.id) return;
    const t = await topicsApi.get(props.projectId, props.columnId, props.id);
    if (!t) {
      loadError.value = '未找到该选题';
      return;
    }
    form.value = { title: t.title, description: t.description ?? '' };
  } catch (e) {
    // A 404 here means the Project / Column / Topic is outside the current session scope.
    // Show a clear message and fall back — never surface the raw Axios error.
    if (is404(e)) {
      toast.error('该项目或选题不在当前会话作用域内，已返回项目列表');
      router.visit('/projects');
      return;
    }
    loadError.value = e instanceof Error ? e.message : '加载失败';
  } finally {
    loading.value = false;
  }
}

function validate(): boolean {
  errors.value = {};
  if (!form.value.title.trim()) errors.value.title = '请填写选题标题';
  return Object.keys(errors.value).length === 0;
}

async function submit(): Promise<void> {
  if (!validate()) return;
  submitting.value = true;
  errors.value = {};
  const payload: TopicInput = {
    title: form.value.title.trim(),
    description: form.value.description.trim() || null,
  };
  try {
    if (mode === 'edit' && props.id) {
      await topicsApi.update(props.projectId, props.columnId, props.id, payload);
      toast.success('选题已更新');
    } else {
      await topicsApi.create(props.projectId, props.columnId, payload);
      toast.success('选题已创建');
    }
    router.visit(topicsUrl);
  } catch (e) {
    // Saving can also fail with a scope 404 if the session project changed.
    if (is404(e)) {
      toast.error('该项目或选题不在当前会话作用域内，已返回项目列表');
      router.visit('/projects');
      return;
    }
    const fe = extractFieldErrors(e);
    if (fe.title) {
      errors.value = { title: fe.title?.[0] };
    } else {
      toast.error(firstErrorMessage(e) ?? '保存失败');
    }
  } finally {
    submitting.value = false;
  }
}

// Re-fetch when Inertia reuses this component across edit targets.
watch(
  () => [props.projectId, props.columnId, props.id, page.props.mode],
  () => {
    load();
  },
);

onMounted(load);
</script>

<template>
  <AdminLayout>
    <Breadcrumb
      :items="[
        { label: project?.name ?? '项目', href: '/projects' },
        { label: column?.name ?? '小栏目', href: `/projects/${projectId}/columns` },
        { label: '选题', href: topicsUrl },
        { label: mode === 'edit' ? '编辑选题' : '新建选题' },
      ]"
    />

    <PageHeader
      :title="mode === 'edit' ? '编辑选题' : '新建选题'"
      :description="mode === 'edit' ? '修改选题的基本信息。' : '在当前栏目下创建选题。'"
    >
      <template #actions>
        <Link
          :href="topicsUrl"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回列表
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" label="加载选题…" />
    <ErrorState v-else-if="loadError" :message="loadError" />

    <form
      v-else
      class="max-w-xl space-y-5 rounded-lg border border-slate-200 bg-white p-6"
      @submit.prevent="submit"
    >
      <div>
        <label class="block text-sm font-medium text-slate-700">
          选题标题 <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="form.title"
          type="text"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="例如：孩子写作业拖拉怎么办"
        />
        <p v-if="errors.title" class="mt-1 text-xs text-rose-600">{{ errors.title }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700">说明</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="可选"
        />
      </div>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="submitting"
          class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
        >
          {{ submitting ? '保存中…' : mode === 'edit' ? '保存修改' : '创建选题' }}
        </button>
        <Link
          :href="topicsUrl"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          取消
        </Link>
      </div>
    </form>
  </AdminLayout>
</template>
