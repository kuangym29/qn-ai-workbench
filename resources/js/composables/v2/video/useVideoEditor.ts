// V2 视频号视频制作 —— 编辑器状态机 composable。
//
// 正式链路（R1 branch-aware）：
//   GET  loadWorkspace(projectId, itemId, branchId)
//   → 编辑 batch_instruction / narration / units（dynamic_instruction / duration / note）
//   → PUT save（白名单 VideoSaveInput）→ 成功后重新 GET（不乐观伪造服务端状态）
//   来源变化「继续沿用」：POST source-change {action:'keep'} → 成功后重新 GET；
//   失败保留本地提醒，不本地清 source_changed。
//
// 生产模块只依赖 videoApi（0 引用 DEV Mock）；DEV 预览 / 测试通过第 4 参注入 client。
// 底部按钮（复制整批视频任务 / 先做试样 / 选择片段生成）保持 UI / emit，
// 等待后端集成（disabled pending integration），本 composable 不开发真实视频生成。
import { computed, ref } from 'vue';
import type {
  VideoClient,
} from './videoApi';
import type {
  VideoEditorWorkspace,
  VideoProduction,
  VideoUnit,
  VideoTimelineItem,
} from '../../../types/v2/video/video';
import {
  buildSaveInput,
  buildTimeline,
  computeTotalDuration,
  normalizeDurationSeconds,
  sortUnitsByOrder,
} from './videoCore';
import { videoApi } from './videoApi';
import { firstErrorMessage } from '../../../api/errors';
import { toast } from '../../../ui/toast';

type Id = number | string;

export function useVideoEditor(
  projectId: Id,
  itemId: Id,
  branchId: Id,
  client: VideoClient = videoApi,
) {
  const video = ref<VideoProduction | null>(null);
  const loading = ref(true);
  const loadError = ref('');
  const saving = ref(false);
  const resolvingSource = ref(false);
  const timelineOpen = ref(false);
  const durationPreview = ref(false);
  const serverDuration = ref(0);
  const serverTimeline = ref<VideoTimelineItem[]>([]);
  const narrationNote = ref('');
  const savedInput = ref('');
  const dirty = computed(() => video.value !== null && JSON.stringify(buildSaveInput(video.value)) !== savedInput.value);

  const totalDuration = computed(() =>
    durationPreview.value && video.value ? computeTotalDuration(video.value.units) : serverDuration.value,
  );
  const timeline = computed(() =>
    durationPreview.value && video.value ? buildTimeline(video.value.units) : serverTimeline.value,
  );
  const sortedUnits = computed(() =>
    video.value ? sortUnitsByOrder(video.value.units) : [],
  );

  function applyWorkspace(ws: VideoEditorWorkspace): void {
    video.value = ws.video;
    serverDuration.value = ws.total_duration;
    serverTimeline.value = ws.timeline;
    narrationNote.value = ws.narration_note;
    durationPreview.value = false;
    savedInput.value = JSON.stringify(buildSaveInput(ws.video));
  }

  /** 加载正式工作负载；silent=true 用于保存 / 来源决策后的后台刷新（不闪整页加载态）。 */
  async function load(silent = false): Promise<void> {
    if (!silent) loading.value = true;
    loadError.value = '';
    try {
      const ws = await client.loadWorkspace(projectId, itemId, branchId);
      applyWorkspace(ws);
    } catch (e) {
      if (!silent) loadError.value = firstErrorMessage(e) ?? '加载失败';
      throw e;
    } finally {
      if (!silent) loading.value = false;
    }
  }

  /** 保存用户可编辑字段；重复点击 guard；成功后重新 GET。 */
  async function save(): Promise<boolean> {
    if (!video.value) return false;
    if (saving.value || resolvingSource.value) return false; // 最小重入 guard
    saving.value = true;
    try {
      await client.save(projectId, itemId, branchId, buildSaveInput(video.value));
      await load(true); // 保存成功：以服务端正式数据为准，重新 GET
      toast.success('已保存视频制作');
      return true;
    } catch (e) {
      toast.error(firstErrorMessage(e) ?? '保存失败，请重试');
      return false;
    } finally {
      saving.value = false;
    }
  }

  /**
   * 来源变化「继续沿用」：POST source-change keep → 成功后重新 GET。
   * 禁止本地直接清 source_changed；失败时保留提醒。
   */
  async function keepSourceChanges(): Promise<boolean> {
    if (!video.value) return false;
    if (saving.value || resolvingSource.value) return false;
    if (!video.value.source_changed) return true;
    const draft = dirty.value ? buildSaveInput(video.value) : null;
    resolvingSource.value = true;
    try {
      await client.resolveSourceChange(projectId, itemId, branchId, { action: 'keep' });
      await load(true); // 决策由服务端记录，重新 GET 确认
      if (draft && video.value) {
        const drafts = new Map(draft.units.map(unit => [unit.source_page_id, unit]));
        const units = video.value.units.map(unit => {
          const editable = drafts.get(unit.source_page_id);
          if (!editable) return unit;
          if (editable.duration_seconds !== unit.duration_seconds) durationPreview.value = true;
          return { ...unit, ...editable };
        });
        video.value = { ...video.value, batch_instruction: draft.batch_instruction, narration: draft.narration, units };
      }
      toast.success(draft ? '已继续沿用来源，制作修改仍待保存' : '已继续沿用当前视频制作');
      return true;
    } catch (e) {
      toast.error(firstErrorMessage(e) ?? '操作失败，请重试');
      return false;
    } finally {
      resolvingSource.value = false;
    }
  }

  function updateBatchInstruction(value: string): void {
    if (!video.value || saving.value || resolvingSource.value) return;
    video.value = { ...video.value, batch_instruction: value };
  }

  function updateNarration(value: string): void {
    if (!video.value || saving.value || resolvingSource.value) return;
    video.value = { ...video.value, narration: value };
  }

  function updateUnit(
    sourcePageId: number,
    patch: Partial<Pick<VideoUnit, 'dynamic_instruction' | 'duration_seconds' | 'note'>>,
  ): void {
    if (!video.value || saving.value || resolvingSource.value) return;
    video.value = {
      ...video.value,
      units: video.value.units.map((u) => {
        if (u.source_page_id !== sourcePageId) return u;
        const next = { ...u, ...patch };
        if (patch.duration_seconds !== undefined) {
          next.duration_seconds = normalizeDurationSeconds(patch.duration_seconds, u.duration_seconds);
          durationPreview.value = true;
        }
        if (patch.note !== undefined) {
          next.note = patch.note === null ? null : patch.note.trim() === '' ? null : patch.note;
        }
        return next;
      }),
    };
  }

  return {
    video,
    loading,
    loadError,
    saving,
    resolvingSource,
    timelineOpen,
    durationPreview,
    narrationNote,
    dirty,
    totalDuration,
    timeline,
    sortedUnits,
    load,
    save,
    keepSourceChanges,
    updateBatchInstruction,
    updateNarration,
    updateUnit,
  };
}
