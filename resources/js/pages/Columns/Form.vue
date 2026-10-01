<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import type { ColumnInput } from '../../api/types';
import { columnsApi } from '../../api/columns';
import { is404, extractFieldErrors, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';

const page = usePage();
const props = defineProps<{ projectId: string; id?: string }>();
const mode = ((page.props.mode as string) ?? 'create') as 'create' | 'edit';

const loading = ref(mode === 'edit');
const loadError = ref('');
const submitting = ref(false);

const form = ref<{ name: string; slug: string; description: string; sort_order: number }>({
  name: '',
  slug: '',
  description: '',
  sort_order: 0,
});
const errors = ref<{ name?: string; slug?: string }>({});

let slugTouched = false;

// DEV-003 slug rule: ^[a-z0-9]+(?:-[a-z0-9]+)*$ — lowercase letters, digits, single hyphens.
const SLUG_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

function slugify(s: string): string {
  if (/[^a-zA-Z0-9\s-]/.test(s)) return '';
  return s
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

watch(
  () => form.value.name,
  (val) => {
    if (!slugTouched) form.value.slug = slugify(val);
  },
);

function onSlugInput(): void {
  slugTouched = true;
}

async function load(): Promise<void> {
  if (mode !== 'edit' || !props.id) return;
  try {
    const c = await columnsApi.get(props.projectId, props.id);
    if (!c) {
      loadError.value = '未找到该小栏目';
      return;
    }
    form.value = {
      name: c.name,
      slug: c.slug,
      description: c.description ?? '',
      sort_order: c.sort_order,
    };
    slugTouched = true;
  } catch (e) {
    // 加载 Column 时若因 scope 变化返回 404，说明该项目/小栏目不在当前会话作用域内。
    // 与 Columns/Index.vue 一致：明确提示，返回 /projects，不把原始 Axios 404 暴露给用户。
    if (is404(e)) {
      toast.error('该项目或小栏目不在当前会话作用域内，已返回项目列表');
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
  if (!form.value.name.trim()) errors.value.name = '请填写小栏目名称';
  if (!form.value.slug.trim()) {
    errors.value.slug = '请填写 slug（路径标识）';
  } else if (!SLUG_RE.test(form.value.slug.trim())) {
    errors.value.slug = 'slug 只能含小写字母、数字，中间可用单个连字符（如 daily-comfort）';
  }
  return Object.keys(errors.value).length === 0;
}

async function submit(): Promise<void> {
  if (!validate()) return;
  submitting.value = true;
  errors.value = {};
  const so = Number(form.value.sort_order);
  // DEV-003: sort_order >= 0, default 0. Never coerce a legal 0 into 1.
  const payload: ColumnInput = {
    name: form.value.name.trim(),
    slug: form.value.slug.trim(),
    description: form.value.description.trim() || null,
    sort_order: Number.isFinite(so) ? so : 0,
  };
  try {
    if (mode === 'edit' && props.id) {
      await columnsApi.update(props.projectId, props.id, payload);
      toast.success('小栏目已更新');
    } else {
      await columnsApi.create(props.projectId, payload);
      toast.success('小栏目已创建');
    }
    router.visit(`/projects/${props.projectId}/columns`);
  } catch (e) {
    // 保存时若因 scope 变化返回 404（例如当前会话 Project 已切换），同样明确提示并回退。
    if (is404(e)) {
      toast.error('该项目或小栏目不在当前会话作用域内，已返回项目列表');
      router.visit('/projects');
      return;
    }
    const fe = extractFieldErrors(e);
    if (fe.name || fe.slug) {
      errors.value = { name: fe.name?.[0], slug: fe.slug?.[0] };
    } else {
      toast.error(firstErrorMessage(e) ?? '保存失败');
    }
  } finally {
    submitting.value = false;
  }
}

// Re-fetch when Inertia reuses this component across edit targets.
watch(
  () => [props.projectId, props.id, page.props.mode],
  () => {
    load();
  },
);

onMounted(load);
</script>

<template>
  <AdminLayout>
    <PageHeader
      :title="mode === 'edit' ? '编辑小栏目' : '新建小栏目'"
      :description="mode === 'edit' ? '修改小栏目的基本信息。' : '在当前项目下创建小栏目。'"
    >
      <template #actions>
        <Link
          :href="`/projects/${projectId}/columns`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回列表
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" label="加载小栏目…" />
    <ErrorState v-else-if="loadError" :message="loadError" />

    <form
      v-else
      class="max-w-xl space-y-5 rounded-lg border border-slate-200 bg-white p-6"
      @submit.prevent="submit"
    >
      <div>
        <label class="block text-sm font-medium text-slate-700">
          小栏目名称 <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="form.name"
          type="text"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="例如：安心小日常"
        />
        <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700">
          Slug（路径标识） <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="form.slug"
          type="text"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="daily-comfort"
          @input="onSlugInput"
        />
        <p class="mt-1 text-xs text-slate-400">
          小写字母、数字与单个连字符；中文名称请手动填写，例如 daily-comfort。
        </p>
        <p v-if="errors.slug" class="mt-1 text-xs text-rose-600">{{ errors.slug }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700">描述</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="可选"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700">排序</label>
        <input
          v-model.number="form.sort_order"
          type="number"
          min="0"
          class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
        />
        <p class="mt-1 text-xs text-slate-400">数字越小越靠前，最小为 0。</p>
      </div>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="submitting"
          class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
        >
          {{ submitting ? '保存中…' : mode === 'edit' ? '保存修改' : '创建小栏目' }}
        </button>
        <Link
          :href="`/projects/${projectId}/columns`"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          取消
        </Link>
      </div>
    </form>
  </AdminLayout>
</template>
