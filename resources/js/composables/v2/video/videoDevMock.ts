// V2 视频号视频制作 —— DEV Mock（DEV ONLY，独立于正式生产模块）。
//
// 正式模块（videoApi / videoCore / useVideoEditor）绝不引用本文件。
// 本文件只被：
//   - VideoDevPreviewApp.vue（浏览器开发预览）
//   - videoDevMock.test.ts / useVideoEditor.test.ts（测试）
// 引用。
//
// 提供：
//   1. buildMockVideoWorkspace()：固定示例工作负载（含系统计算字段）。
//   2. createMockVideoClient()：内存实现 VideoClient（GET / PUT / source-change），
//      并记录全部调用（calls），便于断言 branch-aware URL、PUT 白名单与 keep→GET。
import {
  buildTimeline,
  computeTotalDuration,
} from './videoCore';
import { createVideoClient, type VideoClient, type VideoTransport } from './videoApi';
import type {
  VideoEditorWorkspace,
  VideoProduction,
  VideoSaveInput,
  VideoSourceChangeInput,
} from '../../../types/v2/video/video';

function buildMockVideo(): VideoProduction {
  return {
    id: 1,
    project_id: 1,
    content_item_id: 1,
    title: '孩子说“我自己来”时，先别急着帮',
    source_label: '生活小能力 · 孩子出门总磨蹭',
    batch_instruction:
      '整体节奏舒缓，运镜以固定机位 + 轻微推近为主；人物动作遵循「等一等、不代劳」原则，转场用淡入淡出，避免快速切换。',
    narration:
      '出门前，孩子总有自己的节奏。\n\n他把鞋带绕了一圈又一圈，小手笨拙却很认真。\n\n妈妈站在门口，看着时间一分一秒过去，忍住没有蹲下去。\n\n“你自己来。”她说。\n\n几分钟后，孩子抬起头，眼睛亮亮的：“我系好了！”\n\n那一句“我自己来”，要的不是立刻被满足，而是被允许练习。\n\n你在身边，“我自己来”更有底气。',
    units: [
      { id: 1, order: 1, source_page_id: 101, source_label: '封面', dynamic_instruction: '封面缓慢推近，孩子系鞋带的背影，光线柔和。', duration_seconds: 3, note: null },
      { id: 2, order: 2, source_page_id: 102, source_label: 'P2', dynamic_instruction: '鞋带绕圈的微距特写，轻微旋转。', duration_seconds: 4, note: '注意手部动作连贯' },
      { id: 3, order: 3, source_page_id: 103, source_label: 'P3', dynamic_instruction: '妈妈站在门口看表，镜头缓慢横移。', duration_seconds: 4, note: null },
      { id: 4, order: 4, source_page_id: 104, source_label: 'P4', dynamic_instruction: '孩子抬头说话瞬间定格半秒，再继续。', duration_seconds: 3, note: '眼神特写' },
      { id: 5, order: 5, source_page_id: 105, source_label: 'P5', dynamic_instruction: '孩子眼睛亮起来，镜头快推至面部。', duration_seconds: 3, note: null },
      { id: 6, order: 6, source_page_id: 106, source_label: 'P6', dynamic_instruction: '结尾定格：孩子在前面走，妈妈在后面微笑跟。', duration_seconds: 5, note: '收尾点题' },
    ],
    source_changed: true,
    source_change_summary: '来源内容后来有修改，建议检查本视频是否受影响。',
  };
}

export function buildMockVideoWorkspace(): VideoEditorWorkspace {
  const video = buildMockVideo();
  return {
    video,
    total_duration: computeTotalDuration(video.units),
    timeline: buildTimeline(video.units),
  };
}

export interface MockVideoCall {
  method: 'GET' | 'PUT' | 'POST';
  url: string;
  input?: unknown;
}

export interface MockVideoClient {
  client: VideoClient;
  calls: MockVideoCall[];
  /** 内存当前工作负载（GET 返回其副本）。 */
  workspace: VideoEditorWorkspace;
}

function clone<T>(value: T): T {
  return structuredClone(value);
}

function applySave(ws: VideoEditorWorkspace, input: VideoSaveInput): void {
  ws.video.batch_instruction = input.batch_instruction;
  ws.video.narration = input.narration;
  ws.video.units = ws.video.units.map((u) => {
    const hit = input.units.find((x) => x.source_page_id === u.source_page_id);
    if (!hit) return u;
    return {
      ...u,
      dynamic_instruction: hit.dynamic_instruction,
      duration_seconds: hit.duration_seconds,
      note: hit.note,
    };
  });
  ws.total_duration = computeTotalDuration(ws.video.units);
  ws.timeline = buildTimeline(ws.video.units);
}

/**
 * 创建内存 Mock client（每次调用独立 store，便于测试隔离）。
 * 可注入 failingTransport 场景由测试自行包装；本工厂只提供成功路径。
 */
export function createMockVideoClient(initial?: VideoEditorWorkspace): MockVideoClient {
  const workspace: VideoEditorWorkspace = clone(initial ?? buildMockVideoWorkspace());
  const calls: MockVideoCall[] = [];

  const transport: VideoTransport = {
    async get(url: string) {
      calls.push({ method: 'GET', url });
      return { data: { data: clone(workspace) } };
    },
    async put(url: string, input: unknown) {
      calls.push({ method: 'PUT', url, input });
      applySave(workspace, input as VideoSaveInput);
      return { data: { data: { ok: true } } };
    },
    async post(url: string, input?: unknown) {
      calls.push({ method: 'POST', url, input });
      if (url.endsWith('/source-change')) {
        const action = (input as VideoSourceChangeInput).action;
        if (action === 'keep') {
          // 模拟服务端记录 keep 决策后，来源变化提醒消失。
          workspace.video.source_changed = false;
          workspace.video.source_change_summary = null;
        }
      }
      return { data: { data: { ok: true } } };
    },
  };

  return { client: createVideoClient(transport), calls, workspace };
}
