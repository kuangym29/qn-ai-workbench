<script setup lang="ts">
// V2-P2-C02 独立开发预览 App（DEV ONLY）—— 挂载图文文章编辑器主体。
// 不依赖 Laravel / Inertia / 后端 API；AI 导入使用显式标注的 MOCK providers
// （buildMockImportPreview + 内存 apply，MOCK 位于 articleDevMock.ts，正式生产模块不引用），
// 正式路径由后端 preview / apply 提供。
import { useArticleEditor, newImageDraft } from '../../../composables/v2/article/useArticleEditor';
import { buildMockImportPreview } from '../../../composables/v2/article/articleDevMock';
import type { ArticleFields } from '../../../types/v2/article/article';
import ArticleEditorBody from './ArticleEditorBody.vue';
import ToastHost from '../../ToastHost.vue';

const DEV_ITEM = {
  id: 1,
  project_id: 1,
  title: '示例：孩子说“我自己来”时，先别急着帮',
  core_message: '等待是给孩子练习的空间。',
  story_line: '孩子坚持自己来 → 家长忍住不帮 → 完成后获得底气。',
  column_name: '生活小能力',
  column_slug: 'life-skills',
};

const DEV_FIELDS: ArticleFields = {
  headline: '孩子说“我自己来”时，先别急着帮',
  summary: '当孩子坚持自己来，父母要做的不是代劳，而是等待与托底。',
  body: [
    '出门前，孩子总有自己的节奏。',
    '他把鞋带绕了一圈又一圈，小手笨拙却很认真。',
    '妈妈站在门口，看着时间一分一秒过去，忍住没有蹲下去。',
    '“你自己来。”她说。',
    '几分钟后，孩子抬起头，眼睛亮亮的：“我系好了！”',
    '那一句“我自己来”，要的不是立刻被满足，而是被允许练习。',
    '你在身边，“我自己来”更有底气。',
  ].join('\n\n'),
  image_suggestions: [
    { position: 3, description: '妈妈站在门口看表，孩子低头系鞋带，暖色调俯拍。', note: null },
  ],
  cover_suggestion: '孩子抬头笑着说“我系好了”，妈妈蹲在身后微笑。',
};

const editor = useArticleEditor(1, 1, 1, {
  providers: {
    preview: async (raw) => buildMockImportPreview(raw, editor.fields.value),
    apply: async (raw) => {
      // DEV MOCK apply：用 mock 预览结果把变更合入内存（不请求后端）。
      const p = buildMockImportPreview(raw, editor.fields.value);
      const next = { ...editor.fields.value } as ArticleFields;
      for (const m of p.modified) {
        next[m.field] = m.after as never;
      }
      for (const a of p.added) {
        next.image_suggestions = [
          ...(next.image_suggestions ?? []),
          { position: null, description: a.detail ?? '', note: null },
        ];
      }
      editor.fields.value = next;
      return { item: DEV_ITEM, article: next };
    },
  },
});
editor.item.value = { ...DEV_ITEM };
editor.fields.value = { ...DEV_FIELDS };
editor.loading.value = false;
// DEV：保存只走内存（toast 提示），不请求后端。
editor.save = async () => {
  editor.saving.value = true;
  await new Promise((resolve) => setTimeout(resolve, 200));
  editor.saving.value = false;
  return true;
};
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
      DEV PREVIEW：固定示例数据，AI 导入为 Mock（正式解析由后端 preview 提供）；
      正式数据由 WorkBuddy 后端接线后提供。
    </div>

    <ArticleEditorBody
      :item="editor.item.value"
      :fields="editor.fields.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :phase="editor.phase.value"
      :paste-text="editor.pasteText.value"
      :parse-error="editor.parseError.value"
      :preview="editor.preview.value"
      @update:paste-text="editor.pasteText.value = $event"
      @parse="editor.runPreview()"
      @confirm-import="editor.confirmImport()"
      @cancel-import="editor.closeImport()"
      @save="editor.save()"
      @update-field="(key, value) => editor.updateField(key, value)"
      @add-image="editor.addImage(newImageDraft())"
      @remove-image="editor.removeImage($event)"
      @update-image="(index, patch) => editor.updateImage(index, patch)"
      @back="() => {}"
    />

    <ToastHost />
  </div>
</template>
