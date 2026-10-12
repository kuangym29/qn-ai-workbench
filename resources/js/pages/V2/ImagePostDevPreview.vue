<script setup lang="ts">
// V2-P2-C01 开发预览入口（DEV ONLY）。
//
// 用途：在真实后端（WorkBuddy Phase 2）合入前，本地浏览器预览图片文章编辑器 UI。
// 数据为固定示例，明确标注 DEV；正式导航接线由 Work 主程序集成时完成，
// 本页面不属于正式用户路径。
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import PageHeader from '../../components/PageHeader.vue';
import { useImagePostEditor } from '../../composables/v2/useImagePostEditor';
import ImagePostEditorBody from '../../components/V2/ImagePostEditorBody.vue';

const props = defineProps<{ projectId: string | number }>();

// 固定示例数据（仅预览用，非真实接口返回）。
const DEV_ITEM = {
  id: 1,
  project_id: Number(props.projectId),
  title: '示例：孩子出门总磨蹭',
  core_expression: '少说“快点”，把节奏还给孩子。',
  story_line: '出门场景 → 孩子磨蹭 → 妈妈催促 → 换位等待 → 温和收尾。',
  column_name: '生活小能力',
  column_slug: 'life-skills',
};
const DEV_PAGES = [
  {
    page_no: 1,
    page_type: 'cover' as const,
    narrative_role: '开篇引入',
    new_fact: '出门场景，孩子穿鞋很慢。',
    final_copy: '出门前，孩子总有自己的节奏。',
    visual_evidence: '孩子坐在地上慢慢穿鞋，妈妈站在门口看表。',
    continuity: '引出“催”与“等”的对比。',
    production_note: '画面：俯拍视角，暖色调。',
  },
  {
    page_no: 2,
    page_type: 'content' as const,
    narrative_role: '冲突升级',
    new_fact: '妈妈开始催促。',
    final_copy: '“快点，要迟到了。”',
    visual_evidence: '妈妈弯腰，语气急切。',
    continuity: '孩子回应“我自己来”。',
    production_note: '',
  },
  {
    page_no: 3,
    page_type: 'column_closing' as const,
    narrative_role: '温和收尾',
    new_fact: '妈妈选择等待。',
    final_copy: '你在身边，“我自己来”更有底气。',
    visual_evidence: '妈妈蹲下微笑等待，孩子系好鞋带抬头。',
    continuity: '呼应开篇，点题。',
    production_note: '共享正式收尾句，视频号使用独立版本。',
  },
  {
    page_no: 4,
    page_type: 'fixed_back_cover' as const,
    narrative_role: null,
    new_fact: null,
    final_copy: null,
    visual_evidence: null,
    continuity: null,
    production_note: '使用既有固定封底模板',
  },
];

const editor = useImagePostEditor(props.projectId, 1);
editor.item.value = { ...DEV_ITEM };
editor.pages.value = [...DEV_PAGES];
editor.loading.value = false;
</script>

<template>
  <AdminLayout>
    <PageHeader
      title="图片文章编辑器 · 开发预览"
      description="DEV ONLY：固定示例数据，仅供本地预览 UI；正式数据接入由 WorkBuddy 后端完成后接线。"
    >
      <template #actions>
        <Link
          :href="`/projects/${projectId}/content`"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          返回内容库
        </Link>
      </template>
    </PageHeader>

    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
      本页为开发预览（DEV PREVIEW），不会出现在正式导航中。AI 导入按钮使用纯前端解析器演示。
    </div>

    <ImagePostEditorBody
      :item="editor.item.value"
      :pages="editor.pages.value"
      :loading="editor.loading.value"
      :load-error="editor.loadError.value"
      :saving="editor.saving.value"
      :phase="editor.phase.value"
      :paste-text="editor.pasteText.value"
      :parse-error="editor.parseError.value"
      :preview="editor.preview.value"
      :applied-count="editor.appliedCount.value"
      @update:paste-text="editor.pasteText.value = $event"
      @parse="editor.runParse()"
      @confirm-import="editor.confirmImport()"
      @cancel-import="editor.closeImport()"
      @save="editor.save()"
      @update-page="editor.updatePage($event)"
      @remove-page="editor.removePage($event)"
      @update-core-expression="editor.item.value ? (editor.item.value.core_expression = $event) : null"
      @update-story-line="editor.item.value ? (editor.item.value.story_line = $event) : null"
      @back="router.visit(`/projects/${projectId}/content`)"
    />
  </AdminLayout>
</template>
