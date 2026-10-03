# DEV-W10｜Duplicate Review 人工审核 UI

基线：`origin/main` = `3148a1149cb6f8a5eeae71951d758c2f64a342bf`（含 DEV-D09 / DEV-D10.CLEAN）
分支：`workbuddy/DEV-W10-duplicate-review-ui`
阶段目标：**UI_IMPLEMENTED**（真实 API 由 Codex DEV-D11 并行实现，尚未联调）

---

## 1. 目标与边界

把服务端已经算好的查重结果，在**文案审核阶段**呈现给审核人，并让人工决定可追溯地落库。

本任务只做前端。不修改后端算法、Migration、Model、Controller、路由。

明确不做：Vue 之外的任何后端改动、Vector DB、Embedding、跨字段语义查重、自动改文案、自动删重、Auth Lite、AI 自动裁决、外部搜索。

## 2. 放置位置

接入 **ContentPages Copy Editor**（`resources/js/pages/ContentPages/Editor.vue`），而不是 Production Workspace。

理由：查重属于文案审核阶段。审核人在 Working Copy 阶段就要看到与同项目正式历史版本的重复候选，**在确认正式文案之前**完成判断。挂到 Production Workspace 会把判断推迟到文案已冻结之后，违背 D10 的设计意图。

页面结构 `Project → Column → Topic → Content Item → Copy → Production` 未做任何改变；面板只是 Copy Editor 内的又一个区块，插在「篇目状态对照」与「页面列表 / 页面编辑」两栏之间。

## 3. 冻结契约

严格按 DEV-D11 任务书第五节实现，未增删字段。

| 方向 | 端点 | 返回 / 请求 |
| --- | --- | --- |
| GET | `/api/projects/{p}/columns/{c}/topics/{t}/items/{i}/duplicate-review` | `DuplicateReviewResult` |
| POST | `/api/projects/{p}/columns/{c}/topics/{t}/items/{i}/duplicate-review/decisions` | `DuplicateReviewDecision` |

前端类型落在 `resources/js/api/types.ts`（`DuplicateReview*` 段），adapter 落在 `resources/js/api/duplicateReview.ts`。

### 后端是唯一权威

adapter 只发 id 与枚举值，**不发**文本、分数、阈值或 match_kind：

```ts
{
  query_page_version_id, query_field,
  match_page_version_id, match_field,
  decision, note,
}
```

因此服务端可以自行重算并拒绝一个已不成立的 pairing。UI 侧对应的硬性约束：

- 不计算相似度。`overlap_score` 与 `threshold` 原样渲染，仅做 `× 100` 的百分比格式化。
- 不判断阈值。不存在任何 `if (score > threshold)` 之类的客户端判定。
- 不构造 match_kind。`match_kind` / `original_exact` / `normalized_exact` 全部取自响应。
- 不重排候选。渲染顺序即服务端顺序。
- 无 mock、无 fallback。API 不可用时显示错误态，**绝不**渲染编造的查重结果。

## 4. 文件清单

| 文件 | 动作 | 说明 |
| --- | --- | --- |
| `resources/js/api/duplicateReview.ts` | 新增 | 两个冻结端点的 adapter，68 行 |
| `resources/js/api/types.ts` | 修改 | 追加 `DuplicateReview*` 类型与三组标签常量（+131 行，原有 569 行未动） |
| `resources/js/components/DuplicateCandidateCard.vue` | 新增 | 单个候选卡片 |
| `resources/js/components/DuplicateReviewPanel.vue` | 新增 | 汇总 + 状态机 + 决策提交 |
| `resources/js/pages/ContentPages/Editor.vue` | 修改 | 挂载面板 + 4 处写后刷新（+16 行） |
| `docs/DEV-W10_DUPLICATE_REVIEW_UI.md` | 新增 | 本文档 |

未触碰：`app/Services/DuplicateCheck/**`、`database/migrations/**`、`app/Http/**`、`routes/web.php`。

## 5. 阅读体验

目标是「一眼看懂为什么被判重复」，不是做复杂界面。

- **左右对照**：左侧蓝色块是当前工作稿文案，右侧琥珀色块是命中的正式历史文案，等宽并排。
- **顶部汇总**：已检查字段 / 候选数量 / 已处理 / 未处理四个数字，全部来自服务端计数（`query_count`、`candidate_count` 与对返回行数的天真遍历）。
- **候选卡片头部**：Match Kind 徽标 + 字段 + 页码 + 页类型，右侧显示 `相似度 61.5%` 与 `判定阈值 40.0%`。
- **来源不隐藏**：来源篇目 id、Revision 号、页码、命中类型四列平铺，审核人能直接追溯到具体历史版本。
- **状态标签**：完全相同 / 规范化相同 / 高相似（`DUPLICATE_MATCH_KIND_LABELS`）。
- **已记录决定**：显示最新一条的 `decision`、`decision_no` 与备注。

百分比一律一位小数，由 `(score * 100).toFixed(1)` 得到，数值来源始终是 API 的 `overlap_score`。

## 6. 状态机

| 状态 | 触发 | 表现 |
| --- | --- | --- |
| Loading | 首次进入 / 手动「重新检查」 | `LoadingState`，按钮显示「检查中…」 |
| Error | GET 失败 | `ErrorState` + 重试按钮；404 与其它错误文案区分 |
| Empty（无可查工作稿） | `query_count === 0` | 「当前篇目没有可检查的 Working Copy」 |
| Empty（无候选） | `query_count > 0 && candidate_count === 0` | 「已检查 N 个字段，没有发现…重复候选」 |
| 全部已审核 | `candidate_count > 0 && pending === 0` | 绿色横幅 |
| 部分已审核 | 其余有候选的情况 | 提示剩余数量 + 按 query 分组渲染卡片 |
| 提交失败 | POST 422 / 404 | 面板内红框提示，**不刷新、不清空输入** |

两种 Empty 严格区分，因为它们对用户的下一步动作完全不同：前者要先写文案，后者说明检查已通过。

## 7. 决策提交

三个动作固定为 `confirmed_duplicate` / `ignored` / `false_positive`，由 `DUPLICATE_DECISION_ORDER` 渲染，UI 不提供第四个选项。

备注为可选输入，**状态是卡片本地的**（`ref('')`），因此 422 被拒时用户已输入的备注不会丢失。

提交成功后**整体重新 GET**（`await load()`），不在本地 patch `latest_decision`。原因：决策在服务端是 append-only，本地打补丁会掩盖并发追加的新决定。`decision_no` 与「最新决定」始终来自服务端。

## 8. 与 Copy Editor 的联动

面板在以下四处写操作之后自动重新检查，因为这些操作都会改变工作稿，进而改变 query 集合、并使旧的 `query_page_version_id` 失效（服务端将返回 422）：

| 操作 | 函数 |
| --- | --- |
| 保存草稿（新增工作版本） | `saveDraft()` |
| 修改页面类型 | `changePageType()` |
| 新建页面 | `createPage()` |
| 确认正式文案 | `confirmFormal()` |

联动通过具名 ref `duplicateReviewRef` 调用面板 `defineExpose({ reload: load })`。
刻意使用具名 ref 而非 `ref="a.b"` 形式的点号 ref——后者在 `<script setup>` 下不会写入 setup state。

`markPending()` 与 `saveOrder()` 不改变可查文本，故不触发刷新。

## 9. 自检结论

| 检查项 | 结果 |
| --- | --- |
| TypeScript | 零错误（`vue-tsc --noEmit`） |
| Vite build | 成功 |
| localStorage / sessionStorage | 无。界面完全由 `onMounted → load()` 从服务端重建 |
| 客户端 similarity 算法 | 无。仅 `(score*100).toFixed(1)` 的显示格式化 |
| 客户端 threshold 决策 | 无。不存在与 threshold 比较的分支 |
| mock fallback | 无。`duplicateReview.ts` 不含任何 mock / 静态兜底数据 |
| decision 取值 | 恰好三种，来自 `DUPLICATE_DECISION_ORDER` |
| POST 成功后 | 重新 GET 服务端结果 |
| 422 行为 | 保留面板与用户输入，不刷新 |
| 前端测试框架 | 项目无 vitest / jest，按任务书「不要为测试安装重量依赖」未新增 |

## 10. 当前状态与下一步

本阶段标记 **UI_IMPLEMENTED / WAITING_FOR_D11_API**。

真实 API（Codex DEV-D11）尚未进入 main，因此：

- 本分支**没有**做过真实联调，报告中不声称联调通过。
- 面板在 API 缺失时会显示错误态，这是预期行为，不是缺陷。
- 开发期仅通过类型系统与冻结契约保证形状一致，未在浏览器中跑通真实请求。

等待 Codex 返回 `D11_API_RUNTIME_VERIFIED` 后再进行真实 API 联调，届时需核对：GET 各状态、POST 三种决策、422 保留上下文、append-only 的 `decision_no` 递增、以及跨项目 scope 的 404 行为。

本分支不合并 main。
