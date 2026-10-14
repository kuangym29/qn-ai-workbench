# V2-P3-C05-R1｜图片批次前端合同救援

- 执行：Work 主程序；完成后冻结，不继续真实生图后端。
- Source C05 / Rescue Start HEAD：`363b074e3dd590a129977e7ede45553facae2f88`。
- 分支：`work/v2-p3-c05-r1-image-batch-contract`。
- 来源分支：`doubao/v2-image-batch-frontend`，保留不修改。
- 本任务仅修复 C05 合同与行为，没有合并 Foundation，没有正式后端联调。

## 最终后端合同

所有端点基于 `/api/projects/{p}/items/{i}/branches/{b}/image-batch`。

| 方法 | 请求 | 返回 / 前端处理 |
| --- | --- | --- |
| GET base | 无 | 现有 `ImageBatchWorkspace`；成功清空本地选择与 dirty |
| PUT base | `{batch_instruction: string, batch_note: string|null, units: [{id: number|string, special_requirement: string|null}]}` | 成功标记已保存；不依赖响应体 |
| POST base/run | `{mode: 'full'|'trial'|'partial', unit_ids: (number|string)[]}` | `ImageBatchRunResult`，随后 GET；full 固定空数组 |
| POST base/copy-task | `{}` | `{task: string}`，只复制返回文本 |
| POST base/units/{unit}/source-change | `{action: 'keep'|'regenerate'}` | 成功后 GET；不依赖响应体 |

响应数据由现有 HTTP transport 的 `data` 取出。`task` 是本次明确的复制响应字段，后端后续实现需遵守该合同。

`ImageBatchUnit` 不包含 selected。选择使用本地 `Set<number|string>`；修改选择不产生 dirty、不写回服务端。PUT 白名单排除 page/source/status/assets/history 等系统字段，运行时 patch 也只接收特殊要求。

说明、备注、特殊要求输入即 dirty。run/copy 前自动 PUT；失败保持 dirty、显示错误、不继续动作。来源决策前同样保存草稿，避免后续 GET 丢失用户编辑。GET/PUT 成功后 dirty=false。

## Busy 与状态真源

loading / saving / running / copying / resolving 组成简单 busy guard。锁覆盖 PUT、执行请求、后续 GET 与剪贴板写入全过程；冲突编辑、选择、重复执行与重新 GET 被阻止。没有引入队列或复杂状态机。

生产代码不修改 source_changed、source_change_summary 或 status。keep/regenerate 只请求后端并 GET；服务端拒绝时保留原状态。复制成功仅在真实 clipboard 写入完成后显示。

Mock 仅存在于 DEV Preview 及其测试，模拟五个方法。DEV 提供保存失败开关与请求记录，不接入正式路由。

## TDD 与验证

- 新状态测试最初 19 RED，覆盖缺失的 Draft、选择、来源合同；API 测试缺少 saveDraft 时 RED。
- 实现后补充 PUT 结束后动作仍 pending、后续 GET 锁、剪贴板失败、来源失败和系统字段防覆盖测试。
- 浏览器发现 DEV 入口缺少 CSS；先新增失败测试，再导入现有 app.css。只修复预览环境，保留原页面布局。
- 修复 C05 原有 composable 类型相对路径、缺失 RunPhase 类型及全选事件重载错误，均限自有文件。
- 最终：48 frontend tests PASS；`npm run typecheck` PASS；`npm run build` PASS；`git diff --check` PASS。
- 测试命令：`node --experimental-strip-types --test tests/frontend/v2/imageBatch/*.test.ts tests/frontend/v2/imageBatch/*.test.mjs`。
- 未运行 PHP/MySQL：本次没有后端改动，正式 API 尚待后端实现。

## 真实 DEV 浏览器验收

地址：`http://127.0.0.1:5175/v2-image-batch-preview.html`，通过实际页面输入、点击、请求记录与浏览器剪贴板验证。

| 场景 | 结果 |
| --- | --- |
| 编辑整批说明、备注、P1 特殊要求 | dirty；特殊要求不必先点 inline 保存 |
| full 自动保存 | PUT → POST run `{mode:"full",unit_ids:[]}` → GET |
| trial 勾选 P1 | PUT → POST run `{mode:"trial",unit_ids:["u1"]}` → GET |
| partial 勾选 P2 | PUT → POST run `{mode:"partial",unit_ids:["u2"]}` → GET |
| dirty 复制 | PUT → POST copy-task → 实际剪贴板读回 DEV 服务端任务，含最新备注 |
| 模拟 Draft 保存失败 | run/copy 均只有失败 PUT，未 POST；dirty 与错误保留 |
| 关闭失败开关后重试 | PUT 成功后继续动作 |
| 全选 / 取消 / 单选 / 重新 GET | 10/10、清空、选择 P3 后 GET 回到 0/10 |
| P4 keep / P5 regenerate | 各 POST source-change → GET；来源提醒以 GET 结果消除 |
| 请求期间 | 返回、编辑、选择、生成、复制和重新 GET 禁用 |
| 353×900 窄屏 | 内容区宽度 338px（扣除滚动条）；scrollWidth=clientWidth，无横向溢出；按钮换行，提示可见 |

窄屏截图：`E:/CODEX_nopro_file/2026-10-12/referenced-chatgpt-conversation-this-is-an-2/outputs/c05-r1-narrow.png`。

## Fresh self review

实现后重新阅读 diff、API、composable、页面事件接线、UnitCard 和 Mock 引用，并检索 selected、Prompt、source/status 写入。

- selected 仅作本地选择统计及 UI prop，没有回到 Unit 或 PUT。
- 生产 useImageBatch / imageBatchApi / imageBatchCore 均无 Mock import。
- 前端不组装 Prompt；仅 DEV 模拟服务端返回 fixture 文本。
- 来源和状态赋值只在 DEV 模拟服务端，没有本地假成功。
- dirty 自动保存、失败阻断和全程 busy 锁均由行为测试覆盖。
- 未增加业务字段、额外确认流程或页面区块。

## 范围与后续

仅 C05 自有组件、页面、types/composables、测试、DEV 入口和文档。app、database、routes、Foundation UI、C01–C04、main 均未改。

后续由正式后端实现冻结合同，再单独安排真实 API 集成。本分支完成推送后冻结，不合并 Foundation。
