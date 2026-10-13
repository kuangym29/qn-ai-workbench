// V2 图片批次生产 —— 生产 API client（独立，不引用任何 Mock）。
//
// 端点约定（V2 foundation 风格，branch-aware；正式端点由 WorkBuddy Phase 2 /
// Work 主程序集成对齐，路径不同仅需替换 base）：
//   GET  /api/projects/{p}/items/{i}/branches/{b}/image-batch   → ImageBatchWorkspace
//   POST /api/projects/{p}/items/{i}/branches/{b}/image-batch/run → {mode, unit_ids}
//   POST /api/projects/{p}/items/{i}/branches/{b}/image-batch/copy-task
//
// 正式链路：前端提交执行动作 → 后端组装整批说明 + 最新页面结构 + 当前备注 + 资产状态，
// 并保留真实 AI task snapshot；前端不自己整理 Prompt、不伪造服务端状态。
import type {
  ImageBatchRunInput,
  ImageBatchRunResult,
  ImageBatchWorkspace,
} from '../../types/v2/imageBatch/imageBatch';

interface Transport {
  get(url: string): Promise<{ data: unknown }>;
  post(url: string, input: unknown): Promise<{ data: unknown }>;
}

export function createImageBatchClient(http: Transport) {
  const base = (p: number | string, i: number | string, b: number | string) =>
    '/api/projects/' + Number(p) + '/items/' + Number(i) + '/branches/' + Number(b) + '/image-batch';

  return {
    async loadWorkspace(p: number | string, i: number | string, b: number | string) {
      return (await http.get(base(p, i, b))).data as ImageBatchWorkspace;
    },
    async run(p: number | string, i: number | string, b: number | string, input: ImageBatchRunInput) {
      return (await http.post(base(p, i, b) + '/run', input)).data as ImageBatchRunResult;
    },
    async copyTask(p: number | string, i: number | string, b: number | string) {
      return (await http.post(base(p, i, b) + '/copy-task', {})).data as { task: unknown };
    },
  };
}

export type ImageBatchClient = ReturnType<typeof createImageBatchClient>;
