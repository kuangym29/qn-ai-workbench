# DEV-V2-P3-C04｜「待我审核」轻量审核中心前端

- 任务编号：V2-P3-C04-REVIEW-CENTER-FRONTEND（含 R1 合同收口：正式渠道/内容形式枚举）
- 执行 AI：豆包
- 分支：`doubao/v2-review-center-frontend`（自 `codex/v2-foundation` @ `1fe589f8…` 独立建立）
- 状态：UI_IMPLEMENTED，WAITING_FOR_BACKEND_API（正式后端未合入，REAL_API_INTEGRATED 未达成）

## 1. 产品原则

「待我审核」是自动聚合入口，不是项目管理系统。只解决四件事：

1. 现在有什么需要我审核？
2. 我看什么？
3. 通过还是退回？
4. 退回哪里需要改？

不包含：负责人、deadline、priority、审批人层级、多级审批流、工时、复杂状态机、Kanban。

## 2. 审核动作

正式动作只有两个：

- 通过（approved）
- 退回修改 + 批注（changes_requested）

不包含：驳回、转交、挂起、二审、终审、会签。

## 3. 前端状态

- 只处理 `pending` / `approved` / `changes_requested`。
- 「待我审核」主页面默认只显示 `pending`（`pendingOnly` 过滤）。
- 历史审核以后再做，本任务不做历史入口。

## 4. 页面结构

### 4.1 列表（ReviewList.vue）

每条待审核项至少显示：

- 内容名称（content_title）
- 渠道 / 内容形式（channel · format，中文映射）
- 审核对象（review_target_label，Badge）
- 当前版本（version_label）
- 提交时间（submitted_at）
- 预览摘要（preview_summary，超长截断）

简单列表，整行可点击打开详情；不做 Kanban。

### 4.2 详情（ReviewDetail.vue）

- 内容名称 + `来源 ›`（占位，未来可查看栏目/选题/来源内容，默认不展开）
- 渠道 · 内容形式 + 审核对象 + 当前版本 + 提交时间
- 预览区域：`text`（单段）/ `lines`（多行）/ `items`（键值列表）三种渲染
- 底部：`[通过]` `[退回修改]`
- 退回时展开：批注 textarea（必填，composable 校验）+ 轻量局部定位 `target_label`（可选输入框）+ `[确认退回]` `[取消]`

## 5. 数据合同（冻结展示合同，不自行扩展）

正式渠道与内容形式枚举（R1 收口，与 Foundation 正式枚举一致；一个正式合同，不做历史 alias 池）：

```ts
type ReviewChannel = 'wechat_official' | 'wechat_channels' | 'xiaohongshu';
// wechat_official → 公众号，wechat_channels → 视频号，xiaohongshu → 小红书

type ReviewFormat = 'image_post' | 'article' | 'video';
// image_post → 图片文章，article → 图文文章，video → 视频制作
```

```ts
ReviewItem {
  id: number;
  content_item_id: number;
  branch_id: number;
  status: 'pending' | 'approved' | 'changes_requested';
  content_title: string;
  channel: ReviewChannel;   // 正式枚举，不使用自由 string
  format: ReviewFormat;     // 正式枚举，不使用自由 string
  review_target_type: string;
  review_target_label: string;
  version_label: string;
  submitted_at: string;
  preview_summary: string | null;
}

ReviewDetail extends ReviewItem {
  preview: ReviewPreview | null;
}

ReviewPreview {
  kind: 'text' | 'lines' | 'items';
  text?: string | null;
  lines?: string[] | null;
  items?: { label: string; value: string | null }[] | null;
}

ReviewDecisionInput {
  decision: 'approved' | 'changes_requested';
  comment: string | null;
  target_label?: string | null;   // 轻量局部定位预留
}

ReviewDecisionResult {
  review_id: number;
  decision: 'approved' | 'changes_requested';
  comment: string | null;
  target_label: string | null;
  decided_at: string;
}
```

## 6. 生产 API adapter（reviewApi.ts，独立于 Mock）

端点约定（V2 foundation 风格；正式端点由 WorkBuddy Phase 2 / Work 主程序集成对齐，路径不同仅需替换 base）：

| 方法 | 端点 | 说明 |
|---|---|---|
| list | `GET /api/projects/{project}/reviews?status=pending` | 返回 `{"data": ReviewItem[]}` |
| detail | `GET /api/projects/{project}/reviews/{review}` | 返回 `{"data": ReviewDetail}` |
| decide | `POST /api/projects/{project}/reviews/{review}/decision` | body `ReviewDecisionInput` → `{"data": ReviewDecisionResult}` |

正式链路：前端提交决策 → 后端 append-only 记录 → 前端重新拉取 pending 列表。

前端不伪造服务端状态、不本地算相似度、不本地判定审核结果。

## 7. Mock 隔离

- `reviewDevMock.ts`（DEV ONLY）：固定示例列表（7 条，覆盖小绿书内容 / 图片批次 / 公众号图文 / 视频制作）+ 内存决策。
- 正式生产模块（reviewCore / reviewApi / useReviewCenter）0 引用 Mock。
- Mock 只被 `ReviewDevPreviewApp.vue`（DEV 浏览器预览）与 `reviewDevMock.test.ts` 引用。

## 8. 文件清单（全部新增）

| 文件 | 说明 |
|---|---|
| `resources/js/types/v2/review/review.ts` | 冻结数据合同类型 |
| `resources/js/composables/v2/review/reviewCore.ts` | 纯函数：pendingOnly / 标签映射 / 截断 / detectTargetLabel |
| `resources/js/composables/v2/review/reviewApi.ts` | 生产 API adapter（无 Mock） |
| `resources/js/composables/v2/review/reviewDevMock.ts` | DEV ONLY Mock（列表 + 详情 + 内存决策） |
| `resources/js/composables/v2/review/useReviewCenter.ts` | 状态机：list / detail / decide / 退回批注 |
| `resources/js/components/V2/Review/ReviewList.vue` | 待审核列表 |
| `resources/js/components/V2/Review/ReviewDetail.vue` | 详情 + 通过 / 退回批注 + target_label |
| `resources/js/components/V2/Review/ReviewDevPreviewApp.vue` | DEV 预览 App |
| `resources/js/pages/V2/Review/ReviewCenter.vue` | 正式 Inertia 页面（依赖 projectId prop） |
| `resources/js/v2-review-preview.ts` + `v2-review-preview.html` | 独立 DEV 预览入口（html 在仓库根） |
| `tests/frontend/v2/review/reviewCore.test.ts` | 纯函数测试 |
| `tests/frontend/v2/review/reviewDevMock.test.ts` | DEV Mock 测试 |
| `docs/DEV-V2-P3-C04-REVIEW-CENTER-FRONTEND.md` | 本文档 |

## 9. 边界与禁止

- 未修改：routes/web.php、全局导航、全局 layout、database/**、app/**、PHP tests、Foundation 页面、C01 image_post、C02 article、C03 video、main。
- 正式路由接线由 Work 主程序集成时完成；本任务未加任何路由。
- 审核对象与 target_label 为轻量字符串，不做复杂标注系统。

## 10. 验证

- `node --experimental-strip-types --test tests/frontend/v2/review/*.test.ts`
- `npm run typecheck`
- `npm run build`
- 浏览器：`npm run dev` 后访问 `/v2-review-preview.html`

## 11. 待后端对接项

- 正式 `reviews` API 未实现：list / detail / decision 端点、字段名、404/422 语义以 WorkBuddy 后端实现为准。
- 若后端返回字段命名与本节合同有差异：不擅自改产品结构，由 Work 主程序集成时对齐并记录映射差异。
- `来源 ›` 的栏目 / 选题 / 来源内容查看为未来增强，默认不展开。
