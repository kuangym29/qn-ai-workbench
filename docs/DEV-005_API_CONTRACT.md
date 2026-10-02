# DEV-005｜Topic / ContentItem API 数据契约

本契约基于同源浏览器 Session 与 CSRF，沿用 DEV-003 的 ProjectContext。先通过 `POST /api/projects/{project}/select` 选择当前 Project；URL 中 `{project}` 必须等于 Session 的 `current_project_id`。所有路由返回 JSON `{"data": ...}`，列表为 `{"data": [...]}`，按 `id ASC` 排序。不存在 DELETE 路由。

## 路由

| 对象 | 方法 | 路径 | 用途 |
| --- | --- | --- | --- |
| Topic | GET | `/api/projects/{project}/columns/{column}/topics` | 当前 Column 列表 |
| Topic | POST | `/api/projects/{project}/columns/{column}/topics` | 创建 |
| Topic | GET | `/api/projects/{project}/columns/{column}/topics/{topic}` | 详情 |
| Topic | PATCH | `/api/projects/{project}/columns/{column}/topics/{topic}` | 局部编辑 |
| ContentItem | GET | `/api/projects/{project}/columns/{column}/topics/{topic}/items` | 当前 Topic 篇目列表 |
| ContentItem | POST | `/api/projects/{project}/columns/{column}/topics/{topic}/items` | 创建 |
| ContentItem | GET | `/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}` | 详情 |
| ContentItem | PATCH | `/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}` | 局部编辑 |

## 请求字段

Topic 创建：`title` 必填，字符串，最多 200 字符；`description` 可选，可为 `null`，非空时为字符串。PATCH 可以只提交需要变更的字段；提交 `title` 时不能是空值。

ContentItem 创建：`title` 必填，字符串，最多 200 字符；不接受客户端提交 `copy_status`（包括 `null`），新记录由服务端固定为 `not_started`。PATCH 可以只提交需要变更的字段；普通 PATCH 的 `copy_status` 仅允许 `not_started`、`editing`、`pending_confirmation`，不接受 `null` 或 `confirmed`。正式确认必须使用 DEV-007A 的 `POST .../copy/confirm`，由服务端同时生成完整 CopyRevision 快照。

Topic 写请求禁止 `project_id`、`content_column_id`；ContentItem 写请求另禁止 `topic_id`。即使传入的值与 URL 相同也返回 422。归属只来自服务端逐层校验后的 Project、Column、Topic；创建 ContentItem **不会**自动创建 ProductionTask。

## 响应字段

Topic：`id`, `project_id`, `content_column_id`, `title`, `description`, `created_at`, `updated_at`。

ContentItem：`id`, `project_id`, `content_column_id`, `topic_id`, `title`, `copy_status`, `created_at`, `updated_at`。`copy_status` 输出字符串，不暴露 PHP Enum 对象。时间为 UTC ISO 8601 字符串，缺失时为 `null`。创建返回 201；查询与编辑返回 200。

## Scope 与错误

层级必须完整对应 `Project → ContentColumn → Topic → ContentItem`，所有读取和写入均通过父对象关系查找。当前 Session Project 与 URL 不符、任一祖先错配、目标对象属于其他父节点或不存在，均返回 404；不提供跨 Project 记录的存在性信息。写入字段不合法返回 422，格式为 `{"message":"...","errors":{"field":["..."]}}`。没有用户登录与 Project 成员权限矩阵；Session Project 约束是本阶段的作用域边界，不等于用户授权。
