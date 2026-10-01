# DEV-003 Project / ContentColumn 数据契约

本契约供 WorkBuddy 把 Mock Adapter 替换为真实请求。`/api` 路由位于 Laravel `web` 路由组，使用同源浏览器会话与 CSRF 保护；前端发送 `Accept: application/json`，写请求附带 Laravel CSRF token。所有接口返回 JSON。打开 `/` 的 Inertia 页面时，共享 prop `currentProject` 为当前 Project 对象或 `null`。

## 当前 Project

`POST /api/projects/{project}/select` 把现有 Project 的 ID 写入服务端会话。`GET /api/projects/current` 读取它；未选择或 Project 已删除时返回 `{"data":null}`。客户端可记住选中 ID 用于导航，但后端只从会话读取当前 Project，不以请求体 `project_id` 代替它。前端顺序：加载 Project 列表 → 选择 Project → 调用 select → 加载该 Project 的 Column。切换 Project 时清空旧 Column 缓存。

当前尚无登录、成员关系或 Project 授权来源，选择接口可选任何现有 Project；项目列表也包含全部 Project。此上下文只保证请求间项目作用域一致，**不构成用户级访问授权**。多用户开放前须补身份和 Project 成员校验。

## 路由

| 方法 | 路由 | 用途 | 成功响应 |
| --- | --- | --- | --- |
| GET | `/api/projects` | Project 列表，按 ID 升序 | `200 {"data": Project[]}` |
| POST | `/api/projects` | 创建 Project | `201 {"data": Project}` |
| GET | `/api/projects/current` | 当前 Project | `200 {"data": Project|null}` |
| POST | `/api/projects/{project}/select` | 选择当前 Project | `200 {"data": Project}` |
| PATCH | `/api/projects/{project}` | 编辑当前 Project | `200 {"data": Project}` |
| GET | `/api/projects/{project}/columns` | 当前 Project 的 Column 列表，按 `sort_order`、ID 升序 | `200 {"data": ContentColumn[]}` |
| POST | `/api/projects/{project}/columns` | 创建 Column | `201 {"data": ContentColumn}` |
| GET | `/api/projects/{project}/columns/{column}` | 读取 Column | `200 {"data": ContentColumn}` |
| PATCH | `/api/projects/{project}/columns/{column}` | 编辑 Column | `200 {"data": ContentColumn}` |

`{project}`、`{column}` 是数字 ID。除 select 外，`{project}` 必须等于会话中已选择的 Project；`{column}` 必须由该 Project 的 `contentColumns()` 关系找到。未选 Project、记录不存在或跨 Project 时返回 404，不暴露其他 Project 的记录。非法输入返回 `422 {"message":string,"errors":{"字段":string[]}}`。本轮没有删除接口。

## 响应字段

`Project`：`id: number`、`name: string`、`slug: string`、`description: string|null`、`created_at: string|null`、`updated_at: string|null`。

`ContentColumn`：`id: number`、`project_id: number`、`name: string`、`slug: string`、`description: string|null`、`sort_order: number`、`created_at: string|null`、`updated_at: string|null`。

时间为 UTC ISO 8601 字符串。`project_id` 仅用于响应表明归属，前端不得在写请求中提交。

## 写请求字段与校验

创建 Project：`name`、`slug` 必填；`description` 可选、可为 `null`。编辑 Project：按需提交字段，提交的 `name` / `slug` 不可为空。`name` 最长 120 字符；`slug` 最长 120 字符，只允许小写英文字母、数字和中间单连字符，且全局唯一。`project_id` 禁止提交。

创建 ContentColumn：`name`、`slug` 必填；`description`、`sort_order` 可选。编辑时按需提交字段，提交的 `name` / `slug` 不可为空。`name` 和 `slug` 最长 120 字符；`slug` 规则同上，但只要求同 Project 内唯一。`sort_order` 为不小于 0 的整数，省略时数据库默认 0。`project_id`、`content_column_id` 禁止提交；归属只能来自已校验的当前 Project。
