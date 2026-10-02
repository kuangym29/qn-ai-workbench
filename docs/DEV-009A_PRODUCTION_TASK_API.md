# DEV-009A｜ProductionTask 服务端 API 契约

同源 Session + CSRF。先用 `POST /api/projects/{project}/select` 选定 Project。所有接口逐级验证 `Project → ContentColumn → Topic → ContentItem`，URL Project 必须等于 Session 当前 Project；错配返回 404。公共路径：`/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production`。响应遵循 `{"data":...}`，时间为 UTC ISO 8601。

| 方法 | 路径 | 请求 | 成功响应 |
| --- | --- | --- | --- |
| GET | 公共路径 | 无 | 200；未创建时 `{"data":null}` |
| POST | 公共路径 | 空 Body | 201；创建共享图稿任务 |
| PATCH | 公共路径 | `{"artwork_status":"..."}` | 200；仅更新图稿状态 |
| POST | 公共路径 + `/use-current-copy` | 空 Body | 200；显式切换到最新正式文案 |

Resource 字段：`id`、`project_id`、`content_item_id`、`copy_revision_id`、`copy_revision_no`、`artwork_status`、`is_copy_revision_current`、`created_at`、`updated_at`。`is_copy_revision_current` 比较任务绑定的 Revision ID 与本篇最大 `revision_no` 的正式 Revision ID；不依据 `copy_status` 或 Working Copy 猜测。旧任务允许空 `copy_revision_id`，此时版本号为 null、当前标记为 false。

POST 仅在 ContentItem 为 `confirmed` 且已有正式 Revision 时可创建；服务端锁定篇目、选取最大 `revision_no`，将其 ID 固定为任务制作依据，默认 `artwork_status=not_started`。一篇仅一项任务，重复 POST 返回 422 `errors.production`。不从客户端接收 Revision ID，也不自动生成 ChannelTask。`/copy/confirm` 不自动生成或切换 ProductionTask。

PATCH 只接受一个 `artwork_status`，合法值：`not_applicable`、`not_started`、`in_progress`、`pending_review`、`approved`。`approved` 额外要求篇目仍为 `confirmed` 且任务绑定当前最大正式 Revision，否则 422 `errors.artwork_status`。其余合法状态暂可停留在旧 Revision。PATCH 不改变文案、视频、发布状态，也不接受 `project_id`、`content_item_id` 或 `copy_revision_id`。

文案确认 Revision 2 后，任务仍固定绑定旧 Revision，图稿状态不自动重置，`is_copy_revision_current=false`。`use-current-copy` 要求已有任务、篇目为 `confirmed`、当前正式 Revision 存在，且无任何 ChannelTask；有渠道任务时返回 422 `errors.production`。若已绑定最新 Revision，返回 200 且不改动；否则只更新 `copy_revision_id` 为最新正式 Revision，并显式将 `artwork_status` 重置为 `not_started`。不接受客户端提交的 Revision ID，不修改正式 Revision 或 PageVersion，不创建新任务。

请求中的未知字段和伪造归属字段返回 422；非法 artwork_status 返回 422；未确认、无正式 Revision、重复任务、审批门禁失败或渠道任务阻止切换均返回 422，采用 `{"message":"...","errors":{"field":["..."]}}`。缺失任务的 PATCH 或切换返回 404。当前 Session Project 或任一 URL 祖先错配返回 404。Session Project 是业务作用域，不等于成员授权。

`copy_revision_id` 在数据库可空以兼容旧行，正式 API 创建时必须非空。复合外键 `(project_id, content_item_id, copy_revision_id)` 指向 `content_copy_revisions(project_id, content_item_id, id)` 的复合唯一键，阻止跨 Project 和同 Project 错篇 Revision。该外键限制直接删除被引用的正式 Revision；既有文案历史本身也限制删除 Project / ContentItem。Lite V1.0 暂无删除 API。ChannelTask API、UI、Asset、图像生成与发布流程不在本任务范围。
