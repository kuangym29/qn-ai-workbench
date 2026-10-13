// V2 公众号图文文章 —— 编辑器状态机 composable（R1：branch-aware）。
//
// AI 导入：前端不解析。链路为
//   paste → 后端 preview → 展示字段差异 → 确认 → 后端 apply → GET。
// 客户端仅负责传输和展示，预览原文在确认前固定。
import { computed, ref } from 'vue';
import type {
  ArticleContentItem,
  ArticleFields,
  ArticleImageSuggestion,
  ArticleImportPreview,
} from '../../../types/v2/article/article';
import {
  addImageSuggestion,
  normalizeArticleFields,
  removeImageSuggestion,
  updateImageSuggestion,
} from './articleCore';
import { articleApi } from './articleApi';
import { firstErrorMessage } from '../../../api/errors';
import { toast } from '../../../ui/toast';

export type ArticleImportPhase = 'idle' | 'parsing' | 'preview' | 'error';

export interface ArticleEditorOptions {
  client?: typeof articleApi;
}

export function useArticleEditor(
  projectId: number | string,
  itemId: number | string,
  branchId: number | string,
  options: ArticleEditorOptions = {},
) {
  const item = ref<ArticleContentItem | null>(null);
  const fields = ref<ArticleFields>(normalizeArticleFields(null));
  const loading = ref(false);
  const loadError = ref('');
  const saving = ref(false);

  // ---- AI 导入子状态 ----
  const phase = ref<ArticleImportPhase>('idle');
  const pasteText = ref('');
  const parseError = ref('');
  const preview = ref<ArticleImportPreview | null>(null);
  const previewRaw = ref('');
  const client = options.client ?? articleApi;
  const busy = computed(() => loading.value || saving.value || phase.value === 'parsing');

  async function load(): Promise<void> {
    if (busy.value) return;
    loading.value = true;
    loadError.value = '';
    try {
      const ws = await client.loadWorkspace(projectId, itemId, branchId);
      item.value = ws.item;
      fields.value = normalizeArticleFields(ws.article);
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
    } finally {
      loading.value = false;
    }
  }

  // ---- AI 导入（后端为解析者） ----
  function startImport(): void {
    if (busy.value) return;
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
  }

  function closeImport(): void {
    if (busy.value) return;
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
  }

  async function runPreview(): Promise<void> {
    if (busy.value) return;
    if (!pasteText.value.trim()) {
      parseError.value = '请先粘贴 AI 内容。';
      phase.value = 'error';
      return;
    }
    phase.value = 'parsing';
    parseError.value = '';
    const raw = pasteText.value;
    try {
      preview.value = await client.preview(projectId, itemId, branchId, raw);
      previewRaw.value = raw;
      phase.value = 'preview';
    } catch (e) {
      parseError.value = firstErrorMessage(e) ?? '预览失败';
      phase.value = 'error';
    }
  }

  async function confirmImport(): Promise<void> {
    if (busy.value || !preview.value || phase.value !== 'preview') return;
    phase.value = 'parsing';
    try {
      const ws = await client.apply(projectId, itemId, branchId, previewRaw.value);
      item.value = ws.item;
      fields.value = normalizeArticleFields(ws.article);
      toast.success('已应用 AI 导入');
      phase.value = 'idle';
      closeImport();
    } catch (e) {
      parseError.value = firstErrorMessage(e) ?? '应用失败';
      phase.value = 'error';
    }
  }

  // ---- 手动编辑（修订入口） ----
  function updateField(key: keyof ArticleFields, value: string): void {
    if (busy.value || key === 'image_suggestions') return;
    fields.value = { ...fields.value, [key]: value.trim() === '' ? null : value };
  }

  function updateImageList(list: ArticleImageSuggestion[] | null): void {
    if (busy.value) return;
    fields.value = { ...fields.value, image_suggestions: list };
  }

  function addImage(item: ArticleImageSuggestion): void {
    updateImageList(addImageSuggestion(fields.value.image_suggestions, item));
  }

  function removeImage(index: number): void {
    updateImageList(removeImageSuggestion(fields.value.image_suggestions, index));
  }

  function updateImage(index: number, patch: Partial<ArticleImageSuggestion>): void {
    updateImageList(updateImageSuggestion(fields.value.image_suggestions, index, patch));
  }

  // ---- 保存 ----
  async function save(): Promise<boolean> {
    if (!item.value || busy.value) return false;
    saving.value = true;
    try {
      const ws = await client.save(projectId, itemId, branchId, fields.value);
      item.value = ws.item;
      fields.value = normalizeArticleFields(ws.article);
      toast.success('已保存图文文章');
      return true;
    } catch (e) {
      toast.error(firstErrorMessage(e) ?? '保存失败');
      return false;
    } finally {
      saving.value = false;
    }
  }

  return {
    item,
    fields,
    loading,
    loadError,
    saving,
    busy,
    phase,
    pasteText,
    parseError,
    preview,
    load,
    startImport,
    closeImport,
    runPreview,
    confirmImport,
    updateField,
    addImage,
    removeImage,
    updateImage,
    save,
  };
}

/** 新建配图建议草稿。 */
export function newImageDraft(): ArticleImageSuggestion {
  return { position: null, description: '', note: null };
}
