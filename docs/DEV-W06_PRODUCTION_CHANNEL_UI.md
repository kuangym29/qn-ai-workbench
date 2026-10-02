# DEV-W06｜Production + Channel 统一生产工作台 UI

把已经冻结的 DEV-009A（ProductionTask）与 DEV-W05（ChannelTask）服务端 API 接入真实后台 UI，在单页面内完成一篇 ContentItem 的「制作与渠道」全流程。本轮是纯前端任务：不修改后端状态机、不新增 Migration、不开发 Asset、不调用任何微信真实 API。

## 页面路由

| 方法 | 路径 | 渲染 | 说明 |
| --- | --- | --- | --- |
| GET | `/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production` | `Production/Workspace` | route name `items.production` |

页面路由不带 `/api` 前缀，与 `/api/.../production` 完全不冲突。Inertia route 只透传 `projectId` / `columnId` / `topicId` / `itemId`，**不在服务端查询任何业务数据**；真实数据一律由前端调用正式 API 获取。

## ContentItem 入口

`ContentItems/Index` 每行操作顺序为：编辑文案 / 制作与渠道 / 编辑。入口不按 `copy_status` 隐藏——文案尚未 confirmed 时也允许进入，工作台负责解释为什么暂时不能开始制作。

## 领域类型（resources/js/api/types.ts）

新增严格联合类型与中文标签映射：`ArtworkStatus`、`VideoStatus`、`PublishStatus`、`Channel`，以及 `ARTWORK_STATUS_LABELS` / `VIDEO_STATUS_LABELS` / `PUBLISH_STATUS_LABELS` / `CHANNEL_LABELS`。`ProductionTask`、`ChannelTask`、`ProductionRestartResult` 严格按正式 Resource 字段建模，未凭空增加后端不存在的字段，也未使用 `any` / `Record<string, any>` 绕过状态类型。

`VIDEO_STATUSES` 有意排除 `not_applicable`：视频号不允许停在该状态（服务端会返回 422）。

## API Adapter

`productionTasks.ts`：`get`（返回 `ProductionTask | null`）、`create`（空 body）、`updateArtwork`（只发 `artwork_status`）、`useCurrentCopy`（空 body）。

`channelTasks.ts`：`list`、`create`、`get`、`updateVideo`（只发 `video_status`）、`updatePublish`（按目标状态构造 payload）、`restartWithCurrentCopy`。两个 adapter 都只调用正式 API，**没有 mock 层**，响应统一解 `{"data": ...}`，并且从不发送 `project_id` / `production_task_id` / `copy_revision_id`。

`PublishUpdate` 用可辨识联合把服务端规则编码进类型：`scheduled` 必须带 `scheduled_at`、`published` 只允许可选 `published_at`、`unpublished` 不带任何时间。

## 页面结构

单页面 Lite V1.0，自上而下：Breadcrumb → PageHeader → 篇目/正式文案概览 → 共享图稿制作（含 stale 警告）→ 渠道任务（公众号 / 视频号双 Card，`lg:grid-cols-2`，小屏单列）。沿用既有 slate / white / border 视觉语言，无渐变、动画、图表或第三方 UI 框架。

视觉资产管理尚未实现，Production Card 底部只有一行轻提示，不提供任何上传或预览假界面。

## 正式文案与 Production current

正式文案概览显示篇目标题、`copy_status`、当前正式 Revision 与确认时间。没有正式 Revision 时显示「尚无正式确认版本」。若 `copy_status != confirmed` 但存在 Formal Revision，会明确提示「当前存在已确认 Revision，同时有新的工作文案尚未正式确认」——**不会**误报为「没有正式文案」。

是否为 current 一律采用服务端返回的 `is_copy_revision_current` / `is_production_copy_current`，前端**绝不**用 `copy_status` 自行推断。

## 图稿工作流

不使用无脑下拉框，而是给出明确业务动作：未开始 → 开始制作 → 制作中 → 提交审核 → 待审核 → 审核通过（带确认弹层）→ 已通过。待审核额外提供次级动作「退回制作」。已通过不提供随意回退。历史数据若出现「不适用」也能正常展示，并提供「开始制作」恢复到 `in_progress`。

图稿终态 `approved` 的服务端门禁（当前正式 Revision + 已验收图稿）仍然生效，422 时直接显示服务端第一条真实错误。

## stale Production

当 `is_copy_revision_current = false` 时，Production Card 顶部显示醒目警告，列出「当前图稿仍基于 Revision X / 当前正式文案为 Revision Y」，并且**不自动换版、不自动重置**。

修复动作按是否存在渠道任务分流：

- 无 ChannelTask → 「切换到最新正式文案」调用 `use-current-copy`；确认弹层说明图稿状态会重置为「未开始」，历史 Revision 不会删除。
- 已有 ChannelTask → 「按最新文案重新开始」调用 `restart-with-current-copy`；确认弹层逐条列出五项影响（重绑最新正式文案、图稿重置未开始、未发布渠道状态重置、ChannelTask 不删除、文案历史不删除）。
- 任一渠道 `published` → 显示禁用按钮「无法重新开始」并说明「已有渠道正式发布，不能重置生产链」，不尝试绕过服务端。
- `copy_status != confirmed` 时（DEV-W05 restart 的前提）按钮禁用并提示先完成文案确认，而不是让用户点了才吃 422。

## 渠道任务

两个渠道各自独立成 Card，未创建时也显示，并说明创建条件。前端镜像条件为「Production 存在 + artwork approved + Production current」，不满足时按钮禁用并给出原因；服务端仍是最终 Gate。

- 公众号：创建后 `video_status = not_applicable`，**不提供任何 Video 操作**（公众号没有视频制作阶段），只保留发布区。
- 视频号：创建后 `video_status = not_started`，按未开始 → 开始视频制作 → 提交审核 → 审核通过推进，待审核可退回制作，已通过不回退。
- stale 时视频号的中间状态（未开始 / 制作中 / 待审核）仍可推进，但「审核通过」禁用并提示「共享图稿基于旧版正式文案，不能完成视频终审」。

## 发布工作流与时间处理

状态为 `unpublished` 时提供排期输入 + 「设置排期」+ 可选实际发布时间 + 「标记已发布」；为 `scheduled` 时显示当前排期，并提供「更新排期」「取消排期」「标记已发布」；为 `published` 时只读展示，**不提供取消发布、重新排期或编辑发布时间**，仅提示「已发布记录为历史事实，普通工作流不可回退」。

排期输入使用 `<input type="datetime-local">`。该控件不带时区，提交前统一用 `new Date(value).toISOString()` 把浏览器本地时间换算为 UTC ISO；空值不发送，非法日期直接拦截并提示。服务端返回的 UTC 时间用 `Intl.DateTimeFormat` 按浏览器本地时区显示（页面底部有明确提示），**不使用 `replace('T', ' ')` 假装转换时区**。

标记已发布时**不会**把 `scheduled_at` 一起发回：DEV-W05.1 已规定发布请求携带 `scheduled_at` 会被 422 拒绝，服务端会自动保留库中原值作为排期历史。

发布门禁镜像服务端规则：公众号要求 artwork approved + Production current（无需视频验收）；视频号额外要求 `video_status = approved`，否则提示「请先完成视频审核」。

## ProjectContext

严格沿用 DEV-W04.1 规则：**绝不**根据 URL 调用 `selectProject`，Session Project 是唯一作用域来源。任何 scope 读取出现 404 时提示「当前项目、栏目、选题或篇目不在当前会话作用域内，已返回项目列表」并 `router.visit('/projects')`。`AdminLayout.onSwitch()` 的服务器优先规则未被改动。

页面只在确认 ProductionTask 存在后才请求 `GET /production/channels`，因为父资源不存在时该接口会返回 404。

## 错误与刷新

所有写操作都处理 404 与 422：422 优先显示服务端第一条真实错误（`firstErrorMessage()`，必要时 `extractFieldErrors()`），不显示泛化「请求失败」。每次成功写操作后都重新 GET 正式服务端数据（Production、Channels、ContentItem、Current Revision），不手工猜测状态。

每个主要动作都有独立 busy 状态（creatingProduction / updatingArtwork / switchingCopy / restartingProduction / creatingChannel / updatingVideo / updatingPublish），操作中按钮禁用且文案变为「处理中…」，不会被连续点击。

## 确认弹层

新增无第三方依赖的 `ConfirmDialog.vue`（props: open / title / message / confirmLabel / danger / busy；emits: confirm / cancel），用于图稿审核通过、视频审核通过、`use-current-copy`、`restart-with-current-copy` 与标记正式发布。

## AdminLayout 修正

侧栏「规划中」分组已改为「内容流程」，选题 / 篇目 / 制作与渠道改用 muted 徽章标注「按栏目进入」「按选题进入」「按篇目进入」。这三层没有独立的全局列表路由，必须从上级层级进入，因此只做说明性展示，不提供会误导的 Sidebar 链接。

## Badge 扩展

新增 `danger` 变体（红色），用于 stale 与被阻塞状态。整体配色克制：未开始 default、制作中/待审核 planning、已通过/已发布 active、stale/被阻塞 danger。

## 验证状态

- TypeScript（`vue-tsc --noEmit`）与 Vite build 均通过。
- PHP 全量测试通过，含新增 `ProductionWorkspaceRouteTest`。
- 本轮环境无可安全使用的浏览器自动化与独立测试数据环境，因此**未执行浏览器级 smoke**，状态为 `BROWSER_SMOKE_BLOCKED`；重启生产链等 destructive 操作未在任何真实数据上尝试。
- MySQL 8.4 实机验证仍按 `DATABASE_BASELINE.md` 的 `MYSQL_8_4_VALIDATION_BLOCKED` 说明待补验；本轮未新增 Migration。
