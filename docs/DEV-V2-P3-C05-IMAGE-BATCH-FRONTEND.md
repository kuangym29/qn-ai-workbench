# DEV-V2-P3-C05｜图片批次生产前端（Image Batch）

- 任务编号：V2-P3-C05-IMAGE-BATCH-FRONTEND
- 执行 AI：豆包
- 分支：`doubao/v2-image-batch-frontend`（自新 `codex/v2-foundation` @ `223e2ec3…` 独立建立，未从 C01–C04 堆代码）
- 状态：UI_IMPLEMENTED，WAITING_FOR_BACKEND_API（正式生图后端未合入，REAL_API_INTEGRATED 未达成）

## 1. 产品最高原则

图片生产以「整批」为主，用户不需要逐页重复填写大量 Prompt。核心操作只回答：

1. 这一整批图怎么做？（整批生图说明）
2. 有没有整批特殊备注？（整批备注）
3. 系统拆出来的每一页是否正确？（页面单元列表）
4. 这次我要整批做、先试样，还是只做部分页面？（执行动作区）

## 2. 页面核心结构

顶部（内容名称 / 公众号·图片文章 / 来源 ›）→ 整批生图说明 → 整批备注 → 批次资产检查摘要 → 页面单元列表 → 执行动作区 → 批次历史。

不重复展示大量内容母体字段；不做复杂 CMS / DAM / Kanban。

## 3. 整批生图说明

一个完整大文本区，用于描述整体视觉意图、人物一致性、场景一致性、整体光线 / 风格、这组图片共同要求。不拆成风格 / 光线 / 人物 / 镜头 / 配色字段。

## 4. 整批备注

弱化文本区，只记录当前这一批特殊补充；已有 SOP / 品牌规范 / 人物规范由系统与 Work 环境已有资料处理，不在页面复制。

## 5. 页面单元

系统从内容结构自动得到每一页。每页卡片展示：`P1 / P2…`、页面类型、最终文字（弱展示）、画面证据、人物、场景、特殊要求；单页状态（待制作 / 已生成 / 需修改 / 已通过）。

每页主要允许：查看、轻量修正（仅「特殊要求」inline 编辑）、选择是否参与本次生成（checkbox）。后续发生退回时可针对单页进入修改（本任务预留状态与事件）。

## 6. 资产检查摘要

整批聚合一次（不做逐页重复提醒，不做复杂 DAM）。按人物 / 场景分组，状态：已有 / 缺失 / 本批新建。

## 7. 执行方式

正式三种：

- `[整批生成]`（full）：覆盖全部页面，忽略勾选
- `[先做试样]`（trial）：使用当前勾选（任意一页或多页，不固定必须 P1）
- `[选择部分页面生成]`（partial）：使用页面 checkbox 勾选

试样与部分生成共用勾选；未勾选时提交会提示「请先勾选至少一页」。

## 8. AI / Work 任务

页面支持「复制整批生图任务」。正式任务由系统组装：整批说明 + 最新页面结构 + 当前备注 + 资产状态；用户不自己重新整理 Prompt。本任务只实现 UI / typed contract / event，不开发真实 ChatGPT Work 调用。

## 9. 批次历史

轻量显示：创建时间、包含页面、状态、说明摘要。不做复杂版本管理页面；系统未来自动保留真实 AI task snapshot。本任务只预留「查看历史批次」入口。

## 10. 单页状态

只保留四个轻量状态：待制作 / 已生成 / 需修改 / 已通过。不做十几个图片状态，不做复杂 Kanban。

## 11. 上游内容变化提醒

如果来源页面内容后来修改：页面显示轻量提醒「⚠ 来源内容后来有修改，建议检查本页图片是否受影响。」并提供：查看修改 / 继续沿用 / 重新生成。不显示技术 Revision 编号。

## 12. 数据合同（冻结展示合同，不自行扩展）

```ts
type ImageBatchExecutionMode = 'full' | 'trial' | 'partial';
type ImageBatchUnitStatus = 'pending' | 'generated' | 'needs_revision' | 'approved';
type AssetCheckType = 'character' | 'scene';
type AssetCheckStatus = 'existing' | 'missing' | 'new_in_batch';

AssetCheck { type; name; status }

ImageBatchUnit {
  id; page_id; page_no; page_type;
  final_copy: string | null;      // 弱展示
  visual_evidence: string | null;
  characters: string[]; scene: string | null;
  special_requirement: string | null;
  selected: boolean; status;
  source_changed?: boolean; source_change_summary?: string | null;
}

BatchHistoryItem { id; created_at; page_labels: string[]; status; instruction_summary: string | null }

ImageBatchWorkspace {
  content_title; source_label;
  batch_instruction: string;        // 一个大文本区
  batch_note: string | null;
  assets: AssetCheck[];
  units: ImageBatchUnit[];
  history: BatchHistoryItem[];
}

ImageBatchRunInput { mode; unit_ids: (number | string)[] }
ImageBatchRunResult { batch_id; mode; unit_ids; status }
```

## 13. 生产 API adapter（imageBatchApi.ts，独立于 Mock）

端点约定（V2 foundation 风格，branch-aware；正式端点由 WorkBuddy Phase 2 / Work 主程序集成对齐，路径不同仅需替换 base）：

| 方法 | 端点 | 说明 |
|---|---|---|
| loadWorkspace | `GET /api/projects/{p}/items/{i}/branches/{b}/image-batch` | 返回 `ImageBatchWorkspace` |
| run | `POST …/image-batch/run` | body `{mode, unit_ids}` → `ImageBatchRunResult` |
| copyTask | `POST …/image-batch/copy-task` | 复制整批生图任务（预留） |

正式链路：前端提交执行动作 → 后端组装整批说明 + 最新页面结构 + 当前备注 + 资产状态 → 保留真实 AI task snapshot。前端不自己整理 Prompt、不伪造服务端状态、执行成功后重新拉取工作台。

## 14. Mock 隔离

- `imageBatchDevMock.ts`（DEV ONLY）：固定示例（10 页，封面 / 8 页正文 / 栏目收尾；人物：妈妈已有 / 爸爸已有 / 姐姐缺失；场景：客厅已有 / 儿童房本批新建；P4/P5 来源变化；历史批次 2 条）+ 内存执行（追加历史批次）。
- 正式生产模块（imageBatchCore / imageBatchApi / useImageBatch）0 引用 Mock。
- Mock 只被 `ImageBatchDevPreviewApp.vue`（DEV 浏览器预览）与 `imageBatchDevMock.test.ts` 引用。

## 15. 文件清单（全部新增）

| 文件 | 说明 |
|---|---|
| `resources/js/types/v2/imageBatch/imageBatch.ts` | 冻结数据合同类型 + 展示标签 |
| `resources/js/composables/v2/imageBatch/imageBatchCore.ts` | 纯函数：标签 / 资产分组 / 选择 / 执行目标 / 来源变化 |
| `resources/js/composables/v2/imageBatch/imageBatchApi.ts` | 生产 API client（无 Mock） |
| `resources/js/composables/v2/imageBatch/imageBatchDevMock.ts` | DEV ONLY Mock（工作台 + 内存执行 + 复制任务） |
| `resources/js/composables/v2/imageBatch/useImageBatch.ts` | 状态机：加载 / 选择 / 执行 / 复制任务 / 来源变化 |
| `resources/js/components/V2/ImageBatch/ImageBatchWorkspaceBody.vue` | 工作台主体（全部区块，纯展示 + 局部交互） |
| `resources/js/components/V2/ImageBatch/ImageBatchUnitCard.vue` | 页面单元卡（勾选 + 轻量修正 + 单页状态 + 来源变化） |
| `resources/js/components/V2/ImageBatch/ImageBatchAssetSummary.vue` | 批次资产检查摘要 |
| `resources/js/components/V2/ImageBatch/ImageBatchBatchHistory.vue` | 批次历史（轻量） |
| `resources/js/components/V2/ImageBatch/ImageBatchDevPreviewApp.vue` | DEV 预览 App |
| `resources/js/pages/V2/ImageBatch/ImageBatchEditor.vue` | 正式 Inertia 页面（依赖 projectId / itemId / branchId props） |
| `resources/js/v2-image-batch-preview.ts` + `v2-image-batch-preview.html` | 独立 DEV 预览入口（html 在仓库根） |
| `tests/frontend/v2/imageBatch/imageBatchCore.test.ts` | 纯函数测试 |
| `tests/frontend/v2/imageBatch/imageBatchDevMock.test.ts` | DEV Mock 测试 |
| `docs/DEV-V2-P3-C05-IMAGE-BATCH-FRONTEND.md` | 本文档 |

## 16. 边界与禁止

- 未修改：routes/web.php、全局导航、全局 layout、database/**、app/**、PHP tests、Foundation UI、C01 image_post、C02 article、C03 video、C04 review、main。
- 正式路由接线由 Work 主程序集成时完成；本任务未加任何路由。
- 不开发真实生图后端 / ChatGPT Work 调用 / MediaBatch。

## 17. 验证

- `node --experimental-strip-types --test tests/frontend/v2/imageBatch/*.test.ts`
- `npm run typecheck`
- `npm run build`
- 浏览器：`npm run dev` 后访问 `/v2-image-batch-preview.html`

## 18. 待后端对接项

- 正式 `image-batch` API 未实现：loadWorkspace / run / copy-task 端点、字段名、404/422 语义以 WorkBuddy Phase 2 后端实现为准。
- 若后端返回字段命名与本节合同有差异：不擅自改产品结构，由 Work 主程序集成时对齐并记录映射差异。
- 单页「重新生成 / 继续沿用」为正式决策入口，R1 起调用正式 source-change API（见 19 节）。

---

## 19. R1 合同收口（V2-P3-C05-R1）

R1 只做正式数据合同收口，不重做 UI。改动文件均为 C05 自有文件。

### 19.1 ImageBatchUnit 不再包含 selected

- 勾选只是浏览器当前执行选择，不属于服务端工作台正式数据。
- 前端 composable 本地维护 `selectedUnitIds: Set<number|string>`；单页勾选 / 全选 / 取消选择 / 已选计数 / trial / partial targets 全部基于它。
- `GET workspace` 不返回选择状态；页面重新加载 / 执行成功后重新 GET 时选择清空为正常行为。
- 不为选择状态增加任何数据库概念。

### 19.2 Draft 保存合同（PUT）

```
PUT /api/projects/{project}/items/{item}/branches/{branch}/image-batch
body: {
  batch_instruction: string,
  batch_note: string | null,
  units: [{ id, special_requirement: string | null }]
}
```

只发送用户可编辑的制作补充；`final_copy / visual_evidence / characters / scene / page_type` 属于来源内容 / 系统继承，前端不可写。

### 19.3 Dirty 自动保存（不增加用户流程节点）

- 修改 `batch_instruction / batch_note / 单页 special_requirement` → `dirty = true`（弱化提示：已保存 / 有未保存修改 / 保存中… / 保存失败）。
- 执行「整批生成 / 先做试样 / 选择部分页面生成 / 复制整批生图任务」前若 dirty：先自动 `PUT Draft` → 成功后再执行。
- Draft 保存失败：**不得**继续 run / copy-task，明确提示保存失败。
- 用户不需要额外点一次“保存”。

### 19.4 Run 输入

```
POST …/image-batch/run
body: { mode: 'full' | 'trial' | 'partial', unit_ids: [] }
```

- full：`unit_ids` 可为空，由后端解释为整批。
- trial / partial：必须有 `unit_ids`。
- run body 不重复塞整批说明（run 前已确保 Draft 保存，后端据此创建准确 task snapshot）。

### 19.5 Copy Task

```
POST …/image-batch/copy-task
```

前端不拼 Prompt；后端读取最新 Draft + 最新页面结构 + 资产状态 + 最新特殊要求生成完整 Task。前端只调用 / 获取结果 / 复制。

### 19.6 来源变化决策（source-change）

```
POST …/image-batch/units/{unit}/source-change
body: { action: 'keep' | 'regenerate' }
```

- 「继续沿用」→ `action: 'keep'`；「重新生成」→ `action: 'regenerate'`。
- 成功后**重新 GET 工作台**，不乐观伪造服务端状态。
- 「查看修改」仍为轻量查看（直接读 `source_change_summary`）。

### 19.7 正式 API 端点总表

| 方法 | 端点 | 说明 |
|---|---|---|
| GET | `…/image-batch` | 正式工作台 |
| PUT | `…/image-batch` | 保存整批制作 Draft |
| POST | `…/image-batch/run` | 创建本次批次执行 |
| POST | `…/image-batch/copy-task` | 系统组装完整任务 |
| POST | `…/image-batch/units/{unit}/source-change` | 记录 keep / regenerate 决策 |

### 19.8 Mock 隔离（R1）

- DEV Mock 新增：`applyMockSaveDraft`（更新工作台制作补充并记录 draft）、`applyMockResolveSourceChange`（keep → 清除提示；regenerate → status=needs_revision）。
- 正式模块（imageBatchCore / imageBatchApi / useImageBatch）0 引用 Mock；Mock 仅 DevPreviewApp 与 devMock 测试引用。
- 正式链路：run / copy-task 前 dirty 自动 PUT → 成功后执行；source-change 决策 → POST → GET 刷新。

### 19.9 测试基建（R1）

- 新增 `tests/frontend/v2/imageBatch/useImageBatch.test.ts`（vue reactivity + fake client）：本地选择、reload 清空、dirty 追踪、run/copy-task 前自动保存、PUT 失败不执行、trial/partial 依赖勾选、keep/regenerate 调 API 后 GET。
- `imageBatchCore.test.ts` / `imageBatchDevMock.test.ts` 同步更新（selected 移除、Draft 载荷白名单、Mock saveDraft / source-change）。
- useImageBatch 的跨模块相对导入无扩展名（vite bundler 可解析），node 原生 ESM 需临时 loader：
  `tests/frontend/v2/imageBatch/ts-extension-loader.mjs` + `register-loader.mjs`（测试基建，随本目录提交）。

运行全部测试：

```
node --experimental-strip-types --import ./tests/frontend/v2/imageBatch/register-loader.mjs --test tests/frontend/v2/imageBatch/imageBatchCore.test.ts tests/frontend/v2/imageBatch/imageBatchDevMock.test.ts tests/frontend/v2/imageBatch/useImageBatch.test.ts
```

R1 结果：29 tests / 29 pass；`npm run typecheck` PASS；`npm run build` PASS（889 modules）；浏览器预览交互全链路通过（编辑 → dirty → 执行前自动保存 → 重新 GET 清选择；keep / regenerate → POST → GET 刷新；复制任务自动保存；轻量修正 → dirty）。

### 19.10 R1 文件清单（相对 C05 原 16 文件）

| 文件 | 变化 |
|---|---|
| `types/v2/imageBatch/imageBatch.ts` | 删除 selected；新增 ImageBatchRunPhase / ImageBatchSaveState / Draft / SourceChange 合同 |
| `composables/v2/imageBatch/imageBatchCore.ts` | 选择函数改收 selectedUnitIds；新增 buildDraftPayload |
| `composables/v2/imageBatch/imageBatchApi.ts` | 新增 saveDraft / resolveSourceChange；transport 增加 put |
| `composables/v2/imageBatch/imageBatchDevMock.ts` | 新增 saveDraft / resolveSourceChange 内存模拟 |
| `composables/v2/imageBatch/useImageBatch.ts` | 本地 selectedUnitIds、dirty / saveState、run/copy-task 自动保存、决策 API + GET 刷新 |
| `components/V2/ImageBatch/ImageBatchWorkspaceBody.vue` | selectedUnitIds / dirty / saveState props；弱化保存提示 |
| `components/V2/ImageBatch/ImageBatchUnitCard.vue` | selected 由 props 传入；update emit 只含 id + special_requirement |
| `components/V2/ImageBatch/ImageBatchDevPreviewApp.vue` | devClient 增 saveDraft / resolveSourceChange；新 props |
| `pages/V2/ImageBatch/ImageBatchEditor.vue` | 新 props（selectedUnitIds / dirty / saveState） |
| `tests/frontend/v2/imageBatch/*` | 3 个测试 + 2 个 loader（R1） |
| `docs/DEV-V2-P3-C05-IMAGE-BATCH-FRONTEND.md` | 本文档（R1 章节） |

### 19.11 R1 边界

- 未修改：routes / backend / Foundation / C01–C04 / main。
- 真实生图后端、ChatGPT Work 调用、MediaBatch 不属于本任务。
- 正式端点字段名以 WorkBuddy Phase 2 后端实现为准；差异由 Work 主程序集成时对齐记录。

