<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type {
  Column,
  ContentItem,
  Project,
  SourceReference,
  SourceReferenceInput,
  Topic,
} from '../../api/types';
import {
  ITEM_SCOPED_SOURCE_ROLES,
  SOURCE_AUTHORITY_LABELS,
  SOURCE_ROLE_LABELS,
} from '../../api/types';
import { projectsApi } from '../../api/projects';
import { columnsApi } from '../../api/columns';
import { topicsApi } from '../../api/topics';
import { contentItemsApi } from '../../api/contentItems';
import { itemSourcesApi } from '../../api/sourceReferences';
import { is404, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import Breadcrumb from '../../components/Breadcrumb.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';
import EmptyState from '../../components/EmptyState.vue';
import Badge from '../../components/Badge.vue';
import SourceReferenceForm from '../../components/SourceReferenceForm.vue';

const props = defineProps<{
  projectId: string;
  columnId: string;
  topicId: string;
  itemId: string;
}>();

const scope = computed(() => ({
  projectId: props.projectId,
  columnId: props.columnId,
  topicId: props.topicId,
  itemId: props.itemId,
}));

type Status = 'loading' | 'error' | 'ready';
const status = ref<Status>('loading');
const loadError = ref('');
const project = ref<Project | null>(null);
const column = ref<Column | null>(null);
const topic = ref<Topic | null>(null);
const item = ref<ContentItem | null>(null);
const references = ref<SourceReference[]>([]);

const formOpen = ref(false);
const editing = ref<SourceReference | null>(null);
const saving = ref(false);

const itemsUrl = computed(
  () => `/projects/${props.projectId}/columns/${props.columnId}/topics/${props.topicId}/items`,
);

function handleLoadError(e: unknown): void {
  if (is404(e)) {
    toast.error('当前项目、栏目、选题或篇目不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  loadError.value = firstErrorMessage(e) ?? '加载失败';
  status.value = 'error';
}

function handleActionError(e: unknown): void {
  if (is404(e)) {
    toast.error('当前项目、栏目、选题或篇目不在当前会话作用域内，已返回项目列表');
    router.visit('/projects');
    return;
  }
  toast.error(firstErrorMessage(e) ?? '操作失败');
}

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    const list = await projectsApi.list();
    project.value = list.find((p) => p.id === Number(props.projectId)) ?? null;
    column.value = await columnsApi.get(props.projectId, props.columnId);
    topic.value = await topicsApi.get(props.projectId, props.columnId, props.topicId);
    item.value = await contentItemsApi.get(props.projectId, props.columnId, props.topicId, props.itemId);
    references.value = await itemSourcesApi.list(scope.value);
    status.value = 'ready';
  } catch (e) {
    handleLoadError(e);
  }
}

async function reloadReferences(): Promise<void> {
  references.value = await itemSourcesApi.list(scope.value);
}

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(reference: SourceReference): void {
  editing.value = reference;
  formOpen.value = true;
}

function closeForm(): void {
  formOpen.value = false;
  editing.value = null;
}

async function submit(payload: SourceReferenceInput): Promise<void> {
  saving.value = true;
  try {
    if (editing.value === null) {
      await itemSourcesApi.create(scope.value, payload);
      toast.success('已新增来源引用');
    } else {
      await itemSourcesApi.update(scope.value, editing.value.id, payload);
      toast.success('已保存来源引用');
    }
    closeForm();
    await reloadReferences();
  } catch (e) {
    handleActionError(e);
  } finally {
    saving.value = false;
  }
}

const grouped = computed(() =>
  ITEM_SCOPED_SOURCE_ROLES.map((role) => ({
    role,
    items: references.value.filter((reference) => reference.role === role),
  })),
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
        { label: '来源' },
      ]"
    />

    <PageHeader
      :title="`来源 · ${item?.title ?? ''}`"
      description="记录本篇的最终上图文案与来源脚本路径。同一篇目可以保留多个来源脚本版本。"
    >
      <template #actions>
        <Link
          :href="itemsUrl"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回篇目
        </Link>
        <button
          type="button"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
          @click="openCreate"
        >
          新增来源
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="status === 'loading'" label="加载来源引用…" />
    <ErrorState v-else-if="status === 'error'" :message="loadError" />

    <div v-else class="space-y-6">
      <EmptyState
        v-if="references.length === 0"
        title="还没有来源引用"
        description="可以登记本篇的最终上图文案文件与来源脚本路径，便于追溯正式文案出处。"
      >
        <template #action>
          <button
            type="button"
            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
            @click="openCreate"
          >
            新增来源
          </button>
        </template>
      </EmptyState>

      <div v-for="group in grouped" :key="group.role">
        <div v-if="group.items.length" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">
              {{ SOURCE_ROLE_LABELS[group.role] }}
            </h2>
          </div>
          <table class="w-full text-sm">
            <thead class="bg-white text-left text-xs uppercase tracking-wider text-slate-500">
              <tr>
                <th class="px-4 py-3 font-medium">来源性质</th>
                <th class="px-4 py-3 font-medium">相对路径</th>
                <th class="px-4 py-3 font-medium">备注</th>
                <th class="px-4 py-3 text-right font-medium">操作</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="reference in group.items" :key="reference.id" class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <Badge variant="muted" :label="SOURCE_AUTHORITY_LABELS[reference.authority]" />
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ reference.source_path }}</td>
                <td class="px-4 py-3 text-slate-600">{{ reference.note ?? '—' }}</td>
                <td class="px-4 py-3 text-right">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    @click="openEdit(reference)"
                  >
                    编辑
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="text-xs text-slate-400">
        来源管理只登记路径引用：不上传文件、不浏览本地磁盘、不做同步，也不提供删除。
      </p>
    </div>

    <div
      v-if="formOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
      @click.self="closeForm"
    >
      <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <h3 class="mb-4 text-base font-semibold text-slate-900">
          {{ editing === null ? '新增来源引用' : '编辑来源引用' }}
        </h3>
        <SourceReferenceForm
          :reference="editing"
          :roles="ITEM_SCOPED_SOURCE_ROLES"
          :saving="saving"
          @submit="submit"
          @cancel="closeForm"
        />
      </div>
    </div>
  </AdminLayout>
</template>
