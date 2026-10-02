# DEV-W05｜ChannelTask 渠道流程服务端 API 契约

同源 Session + CSRF。先用 `POST /api/projects/{project}/select` 选定 Project。所有接口逐级验证 `Session current Project → Project → ContentColumn → Topic → ContentItem → ProductionTask → ChannelTask`，任何祖先错配返回 404。响应遵循 `{"data":...}`，时间为 UTC ISO 8601。

正式业务链：`ContentItem → Formal CopyRevision → ProductionTask → ChannelTask`。渠道任务只引用共享生产，不复制篇目与文案。首批渠道只有 `wechat_official`（微信公众号）与 `wechat_channels`（微信视频号），数据库存 Enum 英文 value。

**本轮不调用任何微信真实 API**：`publish_status` 只是内部工作台记录，不请求公众号 / 视频号接口、不保存 access_token、不做自动发布。也不建模渠道专属文案。

## 公共路径

`/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production`

| 方法 | 路径 | 请求 | 成功响应 |
| --- | --- | --- | --- |
| GET | 公共路径 + `/channels` | 无 | 200；已创建渠道数组，未创建时 `{"data":[]}` |
| POST | 公共路径 + `/channels` | `{"channel":"..."}` | 201；创建单个渠道任务 |
| GET | 公共路径 + `/channels/{channel}` | 无 | 200；单个渠道任务 |
| PATCH | 公共路径 + `/channels/{channel}/video` | `{"video_status":"..."}` | 200；仅更新视频状态 |
| PATCH | 公共路径 + `/channels/{channel}/publish` | `{"publish_status":"...","scheduled_at":...,"published_at":...}` | 200；仅更新发布状态与时间 |
| POST | 公共路径 + `/restart-with-current-copy` | 空 Body | 200；显式整链 restart |

不开放任何 DELETE。Lite V1.0 没有 ChannelTask / ProductionTask / Revision 的删除 API。

## Resource 字段

`ChannelTaskResource` 返回 `id`、`project_id`、`production_task_id`、`channel`、`video_status`、`publish_status`、`scheduled_at`、`published_at`、`production_copy_revision_id`、`production_copy_revision_no`、`artwork_status`、`is_production_copy_current`、`created_at`、`updated_at`。

`is_production_copy_current` 的判定严格依据：父 ProductionTask 的 `copy_revision_id` 是否等于该 ContentItem 当前最大 `revision_no` 对应的 ContentCopyRevision.id。**不依据 `copy_status`、`video_status`、`publish_status` 或 `created_at` 推断。** 因此只保存了新草稿（`copy_status` 变 `editing`）但尚未确认 Revision 2 时，Production 依然 current。

## 创建门禁

POST 创建渠道前必须同时满足：

1. ProductionTask 已存在；**父 ProductionTask 不存在时返回 404**（URL 明确位于 `/production/channels` 之下，父资源缺失属于资源不存在，不是业务状态校验失败）；
2. `ProductionTask.artwork_status = approved`；
3. `ProductionTask.copy_revision_id` 非空且等于该 ContentItem 当前最大正式 Revision.id。

不满足第 2、3 条时返回 422 `errors.channel`。理由：渠道衍生只能建立在「当前正式文案 + 已验收共享图稿」之上。

POST 只接受 `channel`。`project_id`、`production_task_id`、`video_status`、`publish_status`、`scheduled_at`、`published_at` 全部由服务端决定，客户端提交一律 422 `prohibited`。

### 默认状态

| Channel | video_status | publish_status | scheduled_at | published_at |
| --- | --- | --- | --- | --- |
| `wechat_official` | `not_applicable` | `unpublished` | null | null |
| `wechat_channels` | `not_started` | `unpublished` | null | null |

一次 POST 只创建一个渠道，绝不自动补建另一个渠道。同一 ProductionTask 重复提交同一 `channel` 返回 422 `errors.channel`；两个不同渠道可并存。GET list 固定按 `wechat_official` → `wechat_channels` 排序，只返回已创建的渠道。

## 视频状态

PATCH `.../video` 只接受 `video_status`，其余字段一律 `prohibited`。

- `wechat_official`：`video_status` 永久 `not_applicable`，任何写入都返回 422 `errors.video_status`（公众号无视频环节，后续 UI 也不提供视频编辑器）。
- `wechat_channels`：合法值 `not_started`、`in_progress`、`pending_review`、`approved`；提交 `not_applicable` 返回 422。
- 视频号进入 `approved` 要求 ProductionTask `artwork_status = approved` 且绑定当前正式 Revision，否则 422 `errors.video_status`。
- 视频状态**不联动发布**：`approved` 不会自动置 `scheduled` / `published`，也不会写 `scheduled_at` / `published_at`。

## 发布状态与时间

PATCH `.../publish` 接受 `publish_status`，并按目标状态校验时间字段。

| 目标状态 | scheduled_at | published_at |
| --- | --- | --- |
| `scheduled` | required | prohibited |
| `published` | prohibited | nullable，缺省 `now()` |
| `unpublished` | prohibited | prohibited |

客户端提交 datetime，Laravel 校验后**统一换算为 UTC 落库**（`2026-10-10T10:00:00+08:00` 存为 `2026-10-10T02:00:00Z`）。本轮不要求 `scheduled_at` 晚于当前时间，系统需要支持历史补录。

注意 `published` 请求**禁止携带 `scheduled_at`**：若该渠道此前处于 `scheduled`，服务端会原样保留库中的 `scheduled_at` 作为排期历史，客户端不得借发布请求改写它。

### scheduled 门禁

要求 ProductionTask `artwork_status = approved` 且绑定当前正式 Revision。视频号额外要求 `video_status = approved`；公众号无需视频验收。否则 422 `errors.publish_status`。已处于 `scheduled` 时可再次提交以修改 `scheduled_at`，不影响视频与图稿。

### published 门禁

进入 `published` 要求 Production approved + current；视频号额外要求视频 `approved`。首次进入 `published` 时客户端可提供 `published_at`，未提供则用 `now()`。若此前是 `scheduled`，**保留原 `scheduled_at` 作为排期历史**，不自动清空。

### published 是普通 Publish API 的终态

已发布是历史事实。当前 `publish_status = published` 时，普通 Publish API 的行为固定为：

| 请求 | 结果 |
| --- | --- |
| `published`（未提交新的 `published_at`） | 200 no-op，保留原 `published_at`，不重新 `now()` |
| `published` + 新的 `published_at` | 422 `errors.published_at` |
| `unpublished` | 422 `errors.publish_status` |
| `scheduled` | 422 `errors.publish_status` |

也就是说 `published` 在本接口是终态，不能用同一个 ChannelTask 把已发布退回排期或未发布。未来若需要再次发布、重新发布或纠错，应另设发布记录模型或管理员纠错动作，不复用本接口改写既有记录。

### unpublished 规则

当前为 `unpublished` 或 `scheduled` 时可置 `unpublished`，服务端统一把 `scheduled_at`、`published_at` 清空。**若当前已是 `published`，置 `unpublished` 返回 422 `errors.publish_status`** —— 已发布是历史事实，不允许通过普通接口抹掉。

## stale Production

确认 Revision 2 后，ProductionTask 仍绑定 Revision 1，服务端**不自动修改** ProductionTask、ChannelTask、`artwork_status`、`video_status`、`publish_status`。Resource 的 `is_production_copy_current` 变为 `false`，已有 ChannelTask 仍可读取。

stale 期间：中间视频状态 `not_started` / `in_progress` / `pending_review` 可保留；但禁止进入终态 —— 视频 `approved`、发布 `scheduled`、发布 `published` 一律 422，直到显式处理 Production 版本。

## 显式整链 restart

`POST .../production/restart-with-current-copy` 用于解决 DEV-009A 留下的死路：`use-current-copy` 只在没有任何 ChannelTask 时可换版，一旦渠道任务已建立后又确认了 Revision 2，就必须由用户主动执行这个 destructive reset。**该动作绝不自动触发。**

前提与结果：

- ProductionTask 必须存在；**至少 1 个 ChannelTask**，否则 422 `errors.production` 并提示改用 `use-current-copy`（不在此重复实现 DEV-009A 的普通换版）。
- ContentItem 需 `copy_status = confirmed` 且存在当前正式 Revision，否则 422 `errors.production`。
- **任一 ChannelTask 已 `published` → 422 `errors.production`**，ProductionTask 与所有 ChannelTask 原样保留。
- Production 已是 current → 200 no-op，不重置任何状态与时间戳。
- 合法 restart 在单个数据库事务内：`copy_revision_id` → 当前最新正式 Revision.id，`artwork_status` → `not_started`，并重置所有 ChannelTask：公众号 `not_applicable`，视频号 `not_started`，`publish_status` → `unpublished`，`scheduled_at` / `published_at` → null。
- **保留 ChannelTask.id 与 channel**，不删除、不重建、不改渠道。不修改 ContentCopyRevision / ContentPage / ContentPageVersion 等文案历史。

## 事务与锁顺序

统一行锁顺序：**ContentItem → ProductionTask → ChannelTask(s)**，适用于渠道创建、视频终态门禁、发布与 restart-with-current-copy，与 DEV-009A `use-current-copy` 的既有顺序保持一致。任何路径都不得反向加锁（ChannelTask → … → ContentItem），否则存在死锁风险。

渠道创建在锁内先检查是否已存在，`unique(production_task_id, channel)` 作为最后一道并发约束；并发的唯一键冲突会被转成 422 `errors.channel`，不会漏成 500。

## 验证状态

Feature 测试在 SQLite 内存库执行（含 migration up、既有行 NULL 默认、rollback / re-run 由迁移层另行实测）。**MySQL 8.4 本轮尚未完成实机验证**：本机 MySQL 8.4.11 在运行，但无可安全使用的独立测试库凭据，未猜测密码也未创建账号，状态为 `MYSQL_8_4_VALIDATION_BLOCKED`。部署前或获得独立测试库凭据后必须补跑 MySQL 8.4 的 migration / rollback / re-run 与本 API 定向测试。

## 错误响应

统一 422 结构 `{"message":"...","errors":{"field":["..."]}}`，字段取 `channel`、`video_status`、`publish_status`、`production`、`scheduled_at`、`published_at`。未知字段与伪造归属字段 422 `prohibited`。非法枚举值 422。缺失 ProductionTask / ChannelTask、未知 Channel、任一祖先错配返回 404。
