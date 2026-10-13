// V2 图片文章编辑器 —— 状态机 composable。
//
// 状态：
//   - 整篇工作负载（item / pages）
//   - 加载 / 错误
//   - AI 导入子状态机：idle → parsing → preview → confirmed / cancelled
//
// 规则：
//   - AI 导入在确认前只产生预览，绝不覆盖 pages；
//   - 局部更新：只粘贴某页内容时，预览只显示受影响的页面，不要求整篇重导；
//   - 保存走 imagePostApi，成功后以服务端返回刷新本地状态。
import { computed, ref } from 'vue';
import type {
  ImagePostContentItem,
  ImagePostImportPreview,
  ImagePostPage,
} from '../../types/v2/imagePost';
import { previewHasChanges } from './imagePostCore';
import { imagePostApi } from './imagePostApi';
import { firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';

export type ImportPhase = 'idle' | 'parsing' | 'preview' | 'error';

export function useImagePostEditor(projectId: number | string, itemId: number | string, branchId: number | string, client = imagePostApi) {
  const item = ref<ImagePostContentItem | null>(null);
  const pages = ref<ImagePostPage[]>([]);
  const loading = ref(true);
  const loadError = ref('');
  const saving = ref(false);
  let loadedSnapshot = '';
  const manualSnapshot = () => JSON.stringify({ item: item.value, pages: pages.value });

  // ---- AI 导入子状态 ----
  const phase = ref<ImportPhase>('idle');
  const pasteText = ref('');
  const parseError = ref('');
  const preview = ref<ImagePostImportPreview | null>(null);
  const previewRaw = ref('');
  /** 已确认并合入 pages 的预览快照；用于导入后提示。 */
  const lastApplied = ref<ImagePostImportPreview | null>(null);

  const appliedCount = computed(() => {
    if (!lastApplied.value) return 0;
    return lastApplied.value.pages.added.length + lastApplied.value.pages.modified.length + lastApplied.value.item.fields.length;
  });

  const hasUnconfirmedChanges = computed(
    () => (preview.value !== null && previewHasChanges(preview.value)) || false,
  );

  async function load(): Promise<boolean> {
    loading.value = true;
    loadError.value = '';
    try {
      const ws = await client.loadWorkspace(projectId, itemId, branchId);
      item.value = ws.item;
      pages.value = ws.pages;
      loadedSnapshot = manualSnapshot();
      return true;
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
      return false;
    } finally {
      loading.value = false;
    }
  }

  // ---- AI 导入 ----
  function startImport(): void {
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
    previewRaw.value = '';
  }

  function closeImport(): void {
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
  }

  /** 解析粘贴内容，进入 preview 阶段；失败进入 error 阶段。 */
  async function runParse(): Promise<void> {
    if (loadedSnapshot && manualSnapshot() !== loadedSnapshot) {
      parseError.value = '请先保存手动修改，再导入 AI 内容。';
      phase.value = 'error';
      return;
    }
    if (!pasteText.value.trim()) {
      parseError.value = '请先粘贴 AI 内容。';
      phase.value = 'error';
      return;
    }
    if (saving.value || applying.value) return;
    phase.value = 'parsing';
    parseError.value = '';
    try {
      const raw = pasteText.value;
      preview.value = await client.preview(projectId, itemId, branchId, raw);
      previewRaw.value = raw;
      phase.value = 'preview';
    } catch (e) {
      parseError.value = firstErrorMessage(e) ?? '解析失败';
      phase.value = 'error';
    }
  }

  const applying = ref(false);
  const applyError = ref('');
  const warnings = ref<import('../../types/v2/imagePost').ImagePostWarning[]>([]);
  async function confirmImport(): Promise<void> {
    if (!preview.value || applying.value || !previewHasChanges(preview.value)) return;
    applying.value = true; applyError.value = '';
    try {
      await client.apply(projectId, itemId, branchId, previewRaw.value);
      lastApplied.value = preview.value;
      closeImport();
      if (await load()) toast.success('AI 导入已保存');
      else toast.error('AI 导入已保存，但重新加载失败，请刷新页面。');
    } catch (e) { applyError.value = firstErrorMessage(e) ?? '导入失败'; }
    finally { applying.value = false; }
  }

  // ---- 手动编辑 ----
  function updatePage(updated: ImagePostPage): void {
    if (loading.value || saving.value || applying.value || phase.value === 'parsing' || phase.value === 'preview') return;
    if (updated.page_type === 'fixed_back_cover' || pages.value.some(p => p.page_no === updated.page_no && p.page_type === 'fixed_back_cover')) return;
    const idx = pages.value.findIndex((p) => p.page_no === updated.page_no);
    if (idx === -1) pages.value.push(updated);
    else pages.value[idx] = updated;
    pages.value = [...pages.value].sort((a, b) => a.page_no - b.page_no);
  }

  // ---- 保存 ----
  async function save(): Promise<boolean> {
    if (!item.value || saving.value || applying.value || phase.value === 'preview' || phase.value === 'parsing') return false;
    saving.value = true;
    try {
      const result = await client.save(projectId, itemId, branchId, {
        core_message: item.value.core_message,
        story_line: item.value.story_line,
        pages: pages.value.filter(p => p.page_type !== 'fixed_back_cover'),
      });
      warnings.value = result.warnings;
      if (await load()) toast.success('已保存整篇图片文章');
      else toast.error('保存已完成，但重新加载失败，请刷新页面。');
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
    pages,
    loading,
    loadError,
    saving,
    phase,
    pasteText,
    parseError,
    preview,
    lastApplied,
    appliedCount,
    hasUnconfirmedChanges,
    load,
    startImport,
    closeImport,
    runParse,
    confirmImport,
    updatePage,
    applying,
    applyError,
    warnings,
    save,
  };
}
