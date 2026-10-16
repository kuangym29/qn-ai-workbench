<script setup lang="ts">
import { watch } from 'vue';
import { usePublication } from '../../../composables/v2/publication/usePublication';
import type { PublicationScope } from '../../../composables/v2/publication/publicationApi';

const props = defineProps<PublicationScope & { dirty: boolean; busy: boolean; ready: boolean }>();
const pub = usePublication(
  () => ({
    projectId: props.projectId,
    itemId: props.itemId,
    branchId: props.branchId,
    target: props.target,
  }),
  () => props.dirty || props.busy || !props.ready,
);
watch(
  () => [props.projectId, props.itemId, props.branchId, props.target],
  () => {
    void pub.load();
  },
  { immediate: true },
);
// 保存成功（dirty 由 true → false）后，按服务器当前保存版本重算发布状态：
// 旧版本的「已发布」不得延续显示到新版本；新版本需重新审核通过后才可发布。
watch(
  () => props.dirty,
  (dirty, wasDirty) => {
    if (wasDirty && !dirty) void pub.load();
  },
);

function formatPublishedAt(value: string | null): string {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString();
}
</script>

<template>
  <section
    class="my-4 flex min-w-0 flex-wrap items-center gap-3 rounded-lg border border-emerald-200 bg-white p-3"
    aria-label="发布"
  >
    <template v-if="pub.published.value">
      <span class="text-sm font-medium text-emerald-700">已发布 · {{ formatPublishedAt(pub.publishedAt.value) }}</span>
    </template>
    <template v-else>
      <button
        type="button"
        class="rounded-md bg-emerald-700 px-3 py-2 text-sm text-white disabled:opacity-50"
        :disabled="
          dirty ||
          busy ||
          !ready ||
          !pub.canPublish.value ||
          pub.loading.value ||
          pub.publishing.value ||
          !!pub.error.value
        "
        @click="pub.publish()"
      >
        {{ pub.publishing.value ? '发布中…' : pub.loading.value ? '加载发布状态…' : '标记已发布' }}
      </button>
      <span v-if="!pub.canPublish.value && pub.reason.value" class="text-sm text-slate-600">{{ pub.reason.value }}</span>
    </template>
    <p v-if="dirty" class="text-sm text-slate-600">请先保存当前修改，再发布。</p>
    <div v-if="pub.error.value" class="min-w-0 text-sm text-red-700" role="alert">
      {{ pub.error.value }}
      <button
        type="button"
        class="underline"
        :disabled="pub.loading.value || pub.publishing.value"
        @click="pub.load()"
      >
        重试
      </button>
    </div>
  </section>
</template>
