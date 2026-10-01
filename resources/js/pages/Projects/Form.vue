<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import type { ProjectInput } from '../../api/types';
import { projectsApi } from '../../api/projects';
import { extractFieldErrors, firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadingState from '../../components/LoadingState.vue';
import ErrorState from '../../components/ErrorState.vue';

const page = usePage();
const mode = ((page.props.mode as string) ?? 'create') as 'create' | 'edit';
const id = page.props.id as string | undefined;

const loading = ref(mode === 'edit');
const loadError = ref('');
const submitting = ref(false);

const form = ref<{ name: string; slug: string; description: string }>({
  name: '',
  slug: '',
  description: '',
});
const errors = ref<{ name?: string; slug?: string }>({});

let slugTouched = false;

// DEV-003 slug rule: ^[a-z0-9]+(?:-[a-z0-9]+)*$ — lowercase letters, digits, single hyphens.
const SLUG_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

function slugify(s: string): string {
  // Only auto-slugify when the name is essentially Latin/numbered. For mostly-Chinese
  // names we leave slug empty so the user supplies a real one (matching DEV-003's rule).
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

// No backend show endpoint: read the project from the already-loaded list by id.
async function load(): Promise<void> {
  if (mode !== 'edit' || !id) return;
  try {
    const list = await projectsApi.list();
    const p = list.find((x) => x.id === Number(id));
    if (!p) {
      loadError.value = '未找到该项目';
      return;
    }
    form.value = { name: p.name, slug: p.slug, description: p.description ?? '' };
    slugTouched = true;
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : '加载失败';
  } finally {
    loading.value = false;
  }
}

function validate(): boolean {
  errors.value = {};
  if (!form.value.name.trim()) errors.value.name = '请填写项目名称';
  if (!form.value.slug.trim()) {
    errors.value.slug = '请填写 slug（路径标识）';
  } else if (!SLUG_RE.test(form.value.slug.trim())) {
    errors.value.slug = 'slug 只能含小写字母、数字，中间可用单个连字符（如 qingning-yujian）';
  }
  return Object.keys(errors.value).length === 0;
}

async function submit(): Promise<void> {
  if (!validate()) return;
  submitting.value = true;
  errors.value = {};
  const payload: ProjectInput = {
    name: form.value.name.trim(),
    slug: form.value.slug.trim(),
    description: form.value.description.trim() || null,
  };
  try {
    if (mode === 'edit' && id) {
      await projectsApi.update(Number(id), payload);
      toast.success('项目已更新');
    } else {
      await projectsApi.create(payload);
      toast.success('项目已创建');
    }
    router.visit('/projects');
  } catch (e) {
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
  () => [page.props.mode, page.props.id],
  () => {
    load();
  },
);

onMounted(load);
</script>

<template>
  <AdminLayout>
    <PageHeader
      :title="mode === 'edit' ? '编辑项目' : '新建项目'"
      :description="mode === 'edit' ? '修改项目的基本信息。' : '创建项目作为最高数据隔离边界。'"
    >
      <template #actions>
        <Link
          href="/projects"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回列表
        </Link>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" label="加载项目…" />
    <ErrorState v-else-if="loadError" :message="loadError" />

    <form
      v-else
      class="max-w-xl space-y-5 rounded-lg border border-slate-200 bg-white p-6"
      @submit.prevent="submit"
    >
      <div>
        <label class="block text-sm font-medium text-slate-700">
          项目名称 <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="form.name"
          type="text"
          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
          placeholder="例如：品牌内容工作台"
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
          placeholder="qingning-yujian"
          @input="onSlugInput"
        />
        <p class="mt-1 text-xs text-slate-400">
          小写字母、数字与单个连字符；中文项目请手动填写，例如 qingning-yujian。
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

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="submitting"
          class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
        >
          {{ submitting ? '保存中…' : mode === 'edit' ? '保存修改' : '创建项目' }}
        </button>
        <Link
          href="/projects"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          取消
        </Link>
      </div>
    </form>
  </AdminLayout>
</template>
