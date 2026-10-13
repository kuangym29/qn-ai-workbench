// V2 图片批次生产 —— 生产 API client（独立，不引用任何 Mock）。
//
// 端点约定（V2 foundation 风格，branch-aware；正式端点由 WorkBuddy Phase 2 /
// Work 主程序集成对齐，路径不同仅需替换 base）：
//   GET  /api/projects/{p}/items/{i}/branches/{b}/image-batch              → ImageBatchWorkspace
//   PUT  /api/projects/{p}/items/{i}/branches/{b}/image-batch              → 保存整批制作 Draft
//   POST /api/projects/{p}/items/{i}/branches/{b}/image-batch/run          → {mode, unit_ids}
//   POST /api/projects/{p}/items/{i}/branches/{b}/image-batch/copy-task    → 系统组装完整 Task
//   POST /api/projects/{p}/items/{i}/branches/{b}/image-batch/units/{unit}/source-change
//                                                                          → {action: keep|regenerate}
//
// 正式链路：
//   - run / copy-task 前若存在未保存修改，前端先 PUT Draft（成功后继续执行）；
//   - copy-task 不接受前端拼好的 Prompt，后端读取最新 Draft + 来源结构组装完整 Task；
//   - source-change 决策由后端记录，成功后前端重新 GET 工作台；
//   - 前端不自己整理 Prompt、不伪造服务端状态。
import type {
  ImageBatchDraftInput,
  ImageBatchRunInput,
  ImageBatchRunResult,
  ImageBatchWorkspace,
  SourceChangeInput,
} from '../../../types/v2/imageBatch/imageBatch';

interface Transport {
  get(url: string): Promise<{ data: unknown }>;
  put(url: string, input: unknown): Promise<{ data: unknown }>;
  post(url: string, input: unknown): Promise<{ data: unknown }>;
}

export function createImageBatchClient(http: Transport) {
  const base = (p: number | string, i: number | string, b: number | string) =>
    '/api/projects/' + Number(p) + '/items/' + Number(i) + '/branches/' + Number(b) + '/image-batch';

  return {
    async loadWorkspace(p: number | string, i: number | string, b: number | string) {
      return (await http.get(base(p, i, b))).data as ImageBatchWorkspace;
    },
    /** 保存整批制作 Draft（只发送用户可编辑的制作补充）。 */
    async saveDraft(p: number | string, i: number | string, b: number | string, input: ImageBatchDraftInput) {
      return (await http.put(base(p, i, b), input)).data as { ok: boolean };
    },
    /** 创建本次批次执行（full 时 unit_ids 可为空，由后端解释为整批）。 */
    async run(p: number | string, i: number | string, b: number | string, input: ImageBatchRunInput) {
      return (await http.post(base(p, i, b) + '/run', input)).data as ImageBatchRunResult;
    },
    /** 复制整批生图任务（系统基于最新 Draft + 来源结构组装完整 Task，前端不拼 Prompt）。 */
    async copyTask(p: number | string, i: number | string, b: number | string) {
      return (await http.post(base(p, i, b) + '/copy-task', {})).data as { task: unknown };
    },
    /** 记录来源变化决策（继续沿用 / 重新生成），成功后前端重新 GET。 */
    async resolveSourceChange(
      p: number | string,
      i: number | string,
      b: number | string,
      unitId: number | string,
      input: SourceChangeInput,
    ) {
      return (await http.post(base(p, i, b) + '/units/' + Number(unitId) + '/source-change', input)).data as {
        ok: boolean;
      };
    },
  };
}

export type ImageBatchClient = ReturnType<typeof createImageBatchClient>;
