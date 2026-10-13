# DEV-V2-P3-C05｜图片批次生产前端（Image Batch）

- 任务编号：V2-P3-C05-IMAGE-BATCH-FRONTEND
- 执行 AI：豆包
- 分支：`doubao/v2-image-batch-frontend`（自新 `codex/v2-foundation` @ `223e2ec3…` 独立建立，未从 C01–C04 堆代码）
- 状态：UI_IMPLEMENTED，WAITING_FOR_BACKEND_API（正式生图后端未合入，REAL_API_INTEGRATED 未达成）
- R1 合同救援见 `DEV-V2-P3-C05-R1-IMAGE-BATCH-CONTRACT.md`；本页合同已按 R1 同步，原豆包分支保持冻结。

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
  status;                          // 勾选由前端 selectedUnitIds Set 独立管理
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
ImageBatchDraftInput { batch_instruction: string; batch_note: string | null; units: { id; special_requirement: string | null }[] }
ImageBatchSourceChangeInput { action: 'keep' | 'regenerate' }
ImageBatchCopyResult { task: string }
```

## 13. 生产 API adapter（imageBatchApi.ts，独立于 Mock）

端点约定（V2 foundation 风格，branch-aware；正式端点由 WorkBuddy Phase 2 / Work 主程序集成对齐，路径不同仅需替换 base）：

| 方法 | 端点 | 说明 |
|---|---|---|
| loadWorkspace | `GET /api/projects/{p}/items/{i}/branches/{b}/image-batch` | 返回 `ImageBatchWorkspace` |
| saveDraft | `PUT …/image-batch` | 只接受整批说明、备注、unit id 与特殊要求 |
| run | `POST …/image-batch/run` | body `{mode, unit_ids}` → `ImageBatchRunResult` |
| copyTask | `POST …/image-batch/copy-task` | body `{}` → `{task: string}`，前端将服务端文本写入剪贴板 |
| resolveSourceChange | `POST …/image-batch/units/{unit}/source-change` | body `{action: 'keep' | 'regenerate'}`，成功后 GET |

正式链路：前端提交执行动作 → 后端组装整批说明 + 最新页面结构 + 当前备注 + 资产状态 → 保留真实 AI task snapshot。前端不自己整理 Prompt、不伪造服务端状态、执行成功后重新拉取工作台。

整批说明、备注、特殊要求输入即标记 dirty；生成与复制前自动 PUT，失败保留 dirty 并终止动作。来源决策前也保存 dirty，避免后续 GET 丢失编辑。GET 成功清空本地勾选和 dirty；选择不写回服务端。

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
- `node --test tests/frontend/v2/imageBatch/*.test.mjs`（真实 composable 状态与异步竞态）
- `npm run typecheck`
- `npm run build`
- 浏览器：`npm run dev` 后访问 `/v2-image-batch-preview.html`

## 18. 待后端对接项

- 正式 `image-batch` 后端尚未实现：后续实现本页冻结的五个方法；鉴权与 404/422 由后端负责，不用前端 Stub 替代。
- 单页「重新生成 / 继续沿用」已调用正式 source-change client 并重新 GET，生产路径不本地修改来源标记或状态。
- DEV Preview 模拟五个方法并展示请求顺序，允许模拟 Draft 保存失败；只用于前端验收，未宣称真实 API 已联调。
