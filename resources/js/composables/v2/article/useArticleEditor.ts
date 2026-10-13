// V2 公众号图文文章 —— 编辑器状态机 composable。
//
// AI 导入：前端不解析。链路为
//   paste → runPreview()（调 previewProvider，默认后端）→ preview 展示
//   → confirmImport()（调 applyProvider，默认后端 apply）→ 刷新工作负载
// 开发预览注入显式 MOCK providers（见 ImagePostDevPreviewApp 同目录 ArticleDevPreviewApp）。
import { computed, ref } from 'vue';
import type {
  ArticleContentItem,
  ArticleFields,
  ArticleImageSuggestion,
  ArticleImportPreview,
  ArticleImportProviders,
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
  /** 默认走后端；开发预览注入 MOCK。 */
  providers?: ArticleImportProviders;
}

export function useArticleEditor(
  projectId: number | string,
  itemId: number | string,
  options: ArticleEditorOptions = {},
) {
  const item = ref<ArticleContentItem | null>(null);
  const fields = ref<ArticleFields>(normalizeArticleFields(null));
  const loading = ref(true);
  const loadError = ref('');
  const saving = ref(false);

  // ---- AI 导入子状态 ----
  const phase = ref<ArticleImportPhase>('idle');
  const pasteText = ref('');
  const parseError = ref('');
  const preview = ref<ArticleImportPreview | null>(null);

  const providers: ArticleImportProviders = options.providers ?? {
    preview: (raw) => articleApi.preview(projectId, itemId, raw),
    apply: (raw) => articleApi.apply(projectId, itemId, raw),
  };

  async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    try {
      const ws = await articleApi.loadWorkspace(projectId, itemId);
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
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
  }

  function closeImport(): void {
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
  }

  async function runPreview(): Promise<void> {
    if (!pasteText.value.trim()) {
      parseError.value = '请先粘贴 AI 内容。';
      phase.value = 'error';
      return;
    }
    phase.value = 'parsing';
    parseError.value = '';
    try {
      preview.value = await providers.preview(pasteText.value);
      phase.value = 'preview';
    } catch (e) {
      parseError.value = firstErrorMessage(e) ?? '预览失败';
      phase.value = 'error';
    }
  }

  async function confirmImport(): Promise<void> {
    if (!preview.value || phase.value !== 'preview') return;
    phase.value = 'parsing';
    try {
      const ws = await providers.apply(pasteText.value);
      item.value = ws.item;
      fields.value = normalizeArticleFields(ws.article);
      toast.success('已应用 AI 导入');
      closeImport();
    } catch (e) {
      parseError.value = firstErrorMessage(e) ?? '应用失败';
      phase.value = 'error';
    }
  }

  // ---- 手动编辑（修订入口） ----
  function updateField(key: keyof ArticleFields, value: string): void {
    fields.value = { ...fields.value, [key]: value.trim() === '' ? null : value };
  }

  function updateImageList(list: ArticleImageSuggestion[] | null): void {
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
    if (!item.value) return false;
    saving.value = true;
    try {
      const ws = await articleApi.save(projectId, itemId, fields.value);
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
