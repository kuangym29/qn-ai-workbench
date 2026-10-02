<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { COPY_STATUSES, COPY_STATUS_LABELS } from '../../api/types';
import type { Column, ContentItemInput, CopyStatus, Project, Topic } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { contentItemsApi } from '../../api/contentItems';
import { is404, extractFieldErrors, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';

const page = usePage();
const props = defineProps<{
  projectId: string;
  columnId: string;
  topicId: string;
  id?: string;
}>();
const mode = ((page.props.mode as string) ?? 'create') as 'create' | 'edit';

const loading = ref(true);
const loadError = ref('');
const submitting = ref(false);
const project = ref<Project | null>(null);
const column = ref<Column | null>(null);
const topic = ref<Topic | null>(null);

const form = ref<{ title: string; copy_status: CopyStatus }>({
  title: '',
  copy_status: 'not_started',
});
const errors = ref<{ title?: string; copy_status?: string }>({});

const itemsUrl = `/projects/${props.projectId}/columns/${props.columnId}/topics/${props.topicId}/items`;

async function load(): Promise<void> {
  loading.value = true;
  try {
    // Breadcrumb context only — all three come from existing formal GET endpoints.
    const list = await projectsApi.list();
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = await columnsApi.get(props.projectId, props.columnId);
    topic.value = await topicsApi.get(props.projectId, props.columnId, props.topicId);

    if (mode !== 'edit' || !props.id) return;
    const item = await contentItemsApi.get(props.projectId, props.columnId, props.topicId, props.id);
    if (!item) {
      loadError.value = '未找到该篇目';
      return;
    }
    form.value = { title: item.title, copy_status: item.copy_status };
  } catch (e) {
    // A 404 here means the Project / Column / Topic / Item is outside the current scope.
    // Show a clear message and fall back — never surface the raw Axios error.
    if (is404(e)) {
      toast.error('该项目或篇目不在当前会话作用域内，已返回项目列表');
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
  if (!form.value.title.trim()) errors.value.title = '请填写篇目标题';
  return Object.keys(errors.value).length === 0;
}

async function submit(): Promise<void> {
  if (!validate()) return;
  submitting.value = true;
  errors.value = {};
  // On create we deliberately do NOT send copy_status: the server defaults it to
  // 'not_started'. On edit all four formal values are allowed.
  const payload: ContentItemInput = { title: form.value.title.trim() };
  if (mode === 'edit') payload.copy_status = form.value.copy_status;
  try {
    if (mode === 'edit' && props.id) {
      await contentItemsApi.update(
        props.projectId,
        props.columnId,
        props.topicId,
        props.id,
        payload,
      );
      toast.success('篇目已更新');
    } else {
      await contentItemsApi.create(props.projectId, props.columnId, props.topicId, payload);
      toast.success('篇目已创建');
    }
    router.visit(itemsUrl);
  } catch (e) {
    // Saving can also fail with a scope 404 if the session project changed.
    if (is404(e)) {
      toast.error('该项目或篇目不在当前会话作用域内，已返回项目列表');
      router.visit('/projects');
      return;
    }
    const fe = extractFieldErrors(e);
    if (fe.title || fe.copy_status) {
      errors.value = { title: fe.title?.[0], copy_status: fe.copy_status?.[0] };
    } else {
      toast.error(firstErrorMessage(e) ?? '保存失败');
    }
  } finally {
    submitting.value = false;
  }
}

// Re-fetch when Inertia reuses this component across edit targets.
watch(
  () => [props.projectId, props.columnId, props.topicId, props.id, page.props.mode],
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
        {
          label: topic?.title ?? '选题',
          href: `/projects/${projectId}/columns/${columnId}/topics`,
        },
        { label: '篇目', href: itemsUrl },
        { label: mode === 'edit' ? '编辑篇目' : '新建篇目' },
      ]"
    />

    <PageHeader
      :title="mode === 'edit' ? '编辑篇目' : '新建篇目'"
      :description="mode === 'edit' ? '修改篇目标题与文案状态。' : '在当前选题下创建篇目。'"
    >
      <template #actions>
        <Link
          :href="itemsUrl"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回列表
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" label="加载篇目…" />
    <ErrorState v-else-if="loadError" :message="loadError" />

    <form
      v-else
      class="max-w-xl space-y-5 rounded-lg border border-slate-200 bg-white p-6"
      @submit.prevent="submit"
    >
      <div>
        <label class="block text-sm font-medium text-slate-700">
          篇目标题 <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="form.title"
          type="text"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="例如：写作业拖拉的 3 个常见原因"
        />
        <p v-if="errors.title" class="mt-1 text-xs text-rose-600">{{ errors.title }}</p>
      </div>

      <div v-if="mode === 'edit'">
        <label class="block text-sm font-medium text-slate-700">文案状态</label>
        <select
          v-model="form.copy_status"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
        >
          <option v-for="value in COPY_STATUSES" :key="value" :value="value">
            {{ COPY_STATUS_LABELS[value] }}
          </option>
        </select>
        <p class="mt-1 text-xs text-slate-400">
          仅使用正式状态：未开始 / 编辑中 / 待确认 / 已确认。
        </p>
        <p v-if="errors.copy_status" class="mt-1 text-xs text-rose-600">
          {{ errors.copy_status }}
        </p>
      </div>
      <p v-else class="text-xs text-slate-400">
        文案状态由服务端默认设为「未开始」，创建后可在编辑中修改。
      </p>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="submitting"
          class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
        >
          {{ submitting ? '保存中…' : mode === 'edit' ? '保存修改' : '创建篇目' }}
        </button>
        <Link
          :href="itemsUrl"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          取消
        </Link>
      </div>
    </form>
  </AdminLayout>
</template>
