<script setup lang="ts">
import { computed } from 'vue';
import type { ImagePostImportPreview } from '../../types/v2/imagePost';
import { IMAGE_POST_FIELD_LABELS } from '../../types/v2/imagePost';
import { PAGE_TYPE_LABELS } from '../../api/types';
import { previewHasChanges } from '../../composables/v2/imagePostCore';
const props = defineProps<{ preview: ImagePostImportPreview; applying: boolean }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();
const hasChanges = computed(() => previewHasChanges(props.preview));
const labels: Record<string,string> = { ...IMAGE_POST_FIELD_LABELS, title: '文章名称', core_message: '核心表达', story_line: '整篇故事线', page_type: '页面类型' };
const changes = computed(() => [
 ...props.preview.item.fields.map(c => ({ ...c, scope: '整篇' })),
 ...props.preview.pages.modified.flatMap(p => p.changes.map(c => ({ ...c, scope: 'P'+p.page_no }))),
]);
</script>
<template>
 <div class="rounded-lg border border-slate-200 bg-white" data-v2-image-post-diff>
  <header class="border-b border-slate-100 px-4 py-3"><h3 class="text-sm font-semibold">变更预览</h3><p class="mt-1 text-xs text-slate-500">确认前不会覆盖正式内容。</p></header>
  <div class="space-y-3 px-4 py-3">
   <section v-if="preview.pages.added.length"><h4 class="text-sm font-semibold text-emerald-700">新增</h4>
    <div v-for="p in preview.pages.added" :key="p.page_no" class="mt-2 rounded border border-emerald-200 bg-emerald-50 p-3">
     <p class="text-sm font-medium">新增 P{{p.page_no}} · {{PAGE_TYPE_LABELS[p.page_type]}}</p>
     <dl v-for="(label,field) in IMAGE_POST_FIELD_LABELS" :key="field" class="mt-1 text-sm"><dt class="text-xs text-slate-500">{{label}}</dt><dd class="whitespace-pre-wrap">{{p[field] ?? '（空）'}}</dd></dl>
    </div>
   </section>
   <section v-if="changes.length"><h4 class="text-sm font-semibold text-amber-700">修改</h4>
    <div v-for="(c,i) in changes" :key="i" class="mt-2 rounded border border-amber-200 bg-amber-50 p-3">
     <p class="text-sm font-medium">修改 {{c.scope}} · {{labels[c.field] ?? c.field}}</p>
     <div class="mt-1 grid gap-3 sm:grid-cols-2"><div><p class="text-xs text-slate-500">修改前</p><p class="whitespace-pre-wrap text-sm line-through">{{c.before ?? '（空）'}}</p></div><div><p class="text-xs text-slate-500">修改后</p><p class="whitespace-pre-wrap text-sm">{{c.after ?? '（空）'}}</p></div></div>
    </div>
   </section>
   <p v-if="preview.pages.unchanged.length" class="text-sm text-slate-500">未变化 {{preview.pages.unchanged.map(p=>'P'+p.page_no).join(' / ')}}</p>
   <ul v-if="preview.warnings.length" class="space-y-1 text-sm text-amber-700"><li v-for="(w,i) in preview.warnings" :key="i">{{w.message}}</li></ul>
   <p v-if="!hasChanges" class="text-sm text-slate-500">没有需要应用的变更。</p>
  </div>
  <footer class="flex justify-end gap-3 border-t border-slate-100 px-4 py-3">
   <button type="button" :disabled="applying" class="rounded border border-slate-300 px-3 py-2 text-sm disabled:opacity-40" @click="emit('cancel')">取消</button>
   <button type="button" :disabled="!hasChanges || applying" class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-40" @click="emit('confirm')">{{applying ? '导入中…' : '确认导入'}}</button>
  </footer>
 </div>
</template>
