// V2 视频号视频制作 —— 编辑器状态机 composable。
//
// 正式链路：loadWorkspace（含系统计算）→ 编辑 batch_instruction / narration /
// units（dynamic_instruction / duration_seconds / note）→ save。
// 底部按钮（复制整批视频任务 / 先做试样 / 选择片段生成）由页面 emit，
// 本 composable 不开发真实视频生成。
import { computed, ref } from 'vue';
import type {
  VideoEditorWorkspace,
  VideoProduction,
  VideoUnit,
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

export function useVideoEditor(projectId: number | string, itemId: number | string) {
  const video = ref<VideoProduction | null>(null);
  const loading = ref(true);
  const loadError = ref('');
  const saving = ref(false);
  const timelineOpen = ref(false);

  const totalDuration = computed(() =>
    video.value ? computeTotalDuration(video.value.units) : 0,
  );
  const timeline = computed(() =>
    video.value ? buildTimeline(video.value.units) : [],
  );
  const sortedUnits = computed(() =>
    video.value ? sortUnitsByOrder(video.value.units) : [],
  );

  function applyWorkspace(ws: VideoEditorWorkspace): void {
    video.value = ws.video;
  }

  async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    try {
      const ws = await videoApi.loadWorkspace(projectId, itemId);
      applyWorkspace(ws);
    } catch (e) {
      loadError.value = firstErrorMessage(e) ?? '加载失败';
    } finally {
      loading.value = false;
    }
  }

  async function save(): Promise<boolean> {
    if (!video.value) return false;
    saving.value = true;
    try {
      const ws = await videoApi.save(projectId, itemId, buildSaveInput(video.value));
      applyWorkspace(ws);
      toast.success('已保存视频制作');
      return true;
    } catch (e) {
      toast.error(firstErrorMessage(e) ?? '保存失败');
      return false;
    } finally {
      saving.value = false;
    }
  }

  function updateBatchInstruction(value: string): void {
    if (!video.value) return;
    video.value = { ...video.value, batch_instruction: value };
  }

  function updateNarration(value: string): void {
    if (!video.value) return;
    video.value = { ...video.value, narration: value };
  }

  function updateUnit(
    unitId: number,
    patch: Partial<Pick<VideoUnit, 'dynamic_instruction' | 'duration_seconds' | 'note'>>,
  ): void {
    if (!video.value) return;
    video.value = {
      ...video.value,
      units: video.value.units.map((u) => {
        if (u.id !== unitId) return u;
        const next = { ...u, ...patch };
        if (patch.duration_seconds !== undefined) {
          next.duration_seconds = normalizeDurationSeconds(patch.duration_seconds, u.duration_seconds);
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
    timelineOpen,
    totalDuration,
    timeline,
    sortedUnits,
    load,
    save,
    updateBatchInstruction,
    updateNarration,
    updateUnit,
  };
}
