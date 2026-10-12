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
import { computed, reactive, ref } from 'vue';
import type {
  ImagePostContentItem,
  ImagePostImportPreview,
  ImagePostPage,
} from '../../types/v2/imagePost';
import { buildImportPreview, parseAiPaste, previewHasChanges } from './imagePostCore';
import { imagePostApi } from './imagePostApi';
import { firstErrorMessage } from '../../api/errors';
import { toast } from '../../ui/toast';

export type ImportPhase = 'idle' | 'parsing' | 'preview' | 'error';

export function useImagePostEditor(projectId: number | string, itemId: number | string) {
  const item = ref<ImagePostContentItem | null>(null);
  const pages = ref<ImagePostPage[]>([]);
  const loading = ref(true);
  const loadError = ref('');
  const saving = ref(false);

  // ---- AI 导入子状态 ----
  const phase = ref<ImportPhase>('idle');
  const pasteText = ref('');
  const parseError = ref('');
  const preview = ref<ImagePostImportPreview | null>(null);
  /** 已确认并合入 pages 的预览快照；用于导入后提示。 */
  const lastApplied = ref<ImagePostImportPreview | null>(null);

  const appliedCount = computed(() => {
    if (!lastApplied.value) return 0;
    return lastApplied.value.added.length + lastApplied.value.modified.length;
  });

  const hasUnconfirmedChanges = computed(
    () => (preview.value !== null && previewHasChanges(preview.value)) || false,
  );

  async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    try {
      const ws = await imagePostApi.loadWorkspace(projectId, itemId);
      item.value = ws.item;
      pages.value = ws.pages;
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
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
  }

  function closeImport(): void {
    phase.value = 'idle';
    pasteText.value = '';
    parseError.value = '';
    preview.value = null;
  }

  /** 解析粘贴内容，进入 preview 阶段；失败进入 error 阶段。 */
  async function runParse(): Promise<void> {
    if (!pasteText.value.trim()) {
      parseError.value = '请先粘贴 AI 内容。';
      phase.value = 'error';
      return;
    }
    phase.value = 'parsing';
    parseError.value = '';
    // 解析是纯前端纯函数，保留微任务边界以统一 loading 表现。
    await new Promise((resolve) => setTimeout(resolve, 120));
    try {
      const result = parseAiPaste(pasteText.value);
      if (result.pages.length === 0) {
        parseError.value = result.warnings.join('；') || '未能解析出任何页面。';
        phase.value = 'error';
        return;
      }
      preview.value = buildImportPreview(pages.value, result.pages);
      // 无任何变更时也进入 preview，让用户看到「无变化」的明确反馈。
      phase.value = 'preview';
    } catch (e) {
      parseError.value = firstErrorMessage(e) ?? '解析失败';
      phase.value = 'error';
    }
  }

  /**
   * 确认导入：把预览中的新增/修改合入 pages。
   * 这里只更新本地内存状态；持久化由用户在编辑页点击「保存」统一提交，
   * 避免多次小请求。fixed_back_cover 等保护性处理由页面/组件层负责。
   */
  function confirmImport(): void {
    if (!preview.value) return;
    const next = new Map<number, ImagePostPage>();
    for (const p of pages.value) next.set(p.page_no, p);
    for (const p of preview.value.added) next.set(p.page_no, { ...p });
    for (const m of preview.value.modified) {
      const target = next.get(m.page_no);
      if (target) {
        next.set(m.page_no, { ...target, [m.field]: m.after });
      }
    }
    pages.value = [...next.values()].sort((a, b) => a.page_no - b.page_no);
    lastApplied.value = preview.value;
    closeImport();
  }

  // ---- 手动编辑 ----
  function updatePage(updated: ImagePostPage): void {
    const idx = pages.value.findIndex((p) => p.page_no === updated.page_no);
    if (idx === -1) pages.value.push(updated);
    else pages.value[idx] = updated;
    pages.value = [...pages.value].sort((a, b) => a.page_no - b.page_no);
  }

  function removePage(pageNo: number): void {
    pages.value = pages.value.filter((p) => p.page_no !== pageNo);
  }

  // ---- 保存 ----
  async function save(): Promise<boolean> {
    if (!item.value) return false;
    saving.value = true;
    try {
      const ws = await imagePostApi.save(projectId, itemId, {
        core_expression: item.value.core_expression,
        story_line: item.value.story_line,
        pages: pages.value,
      });
      item.value = ws.item;
      pages.value = ws.pages;
      toast.success('已保存整篇图片文章');
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
    removePage,
    save,
  };
}

/** 单页本地编辑状态（由页面卡使用）。 */
export function emptyPageDraft(pageNo: number, pageType: ImagePostPage['page_type'] = 'content') {
  return {
    page_no: pageNo,
    page_type: pageType,
    narrative_role: '',
    new_fact: '',
    final_copy: '',
    visual_evidence: '',
    continuity: '',
    production_note: '',
  };
}
