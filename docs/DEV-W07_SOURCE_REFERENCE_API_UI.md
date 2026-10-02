# DEV-W07｜SourceReference Lite API + 来源管理 UI

在 DEV-008A 数据层与 DEV-008B 历史导入之上，提供真实的来源引用 HTTP API 与工作台界面，让用户可以查看、新增与修正来源引用。

**本轮 Migration 数量为 0**：`source_references` 表结构完全不变，所有能力都在既有 schema 之上实现。

## 业务边界

来源管理只是**引用管理**，不是文件管理。系统只记录「品牌源根目录下的相对路径 + 角色」，因此：

- **不上传文件**，没有上传 / 拖拽 / 文件选择器；
- **不浏览本地磁盘**，不读取文件内容；
- **不检查文件是否存在**——工作台服务器未来可能并不拥有用户本机的品牌源目录，做存在性检查会产生假失败；
- **不提供删除**：来源引用属于 provenance 数据，Lite V1.0 只允许新增、查看、修正；删除 / 归档需独立设计。

## 角色与作用域

五个 Role 由 `SourceRole` 定义，并自带 `defaultAuthority()` 与 `isContentItemScoped()`，本轮直接复用，不另建硬编码清单。

| Role | 中文 | Authority | 作用域 |
| --- | --- | --- | --- |
| `final_image_copy` | 最终上图文案 | authoritative | Item |
| `source_script` | 来源脚本 | evidence | Item |
| `content_ledger` | 内容台账 | index | Project |
| `closing_line_registry` | 收尾句台账 | reference | Project |
| `navigation_index` | 导航索引 | index | Project |

**authority 永远由服务端按 role 派生**，客户端不得提交（`prohibited`）。PATCH 修改 role 时 authority 同步重算为新 role 的默认值，不保留旧值。

## 作用域规则

两条路径各自只管理一种 Role 集合：

| 路径 | 管理的 Role |
| --- | --- |
| `/api/projects/{project}/sources` | `content_ledger` / `closing_line_registry` / `navigation_index` |
| `/api/.../items/{item}/sources` | `final_image_copy` / `source_script` |

- 用 Project 路径创建 Item 级 role → 422；
- 用 Item 路径创建 Project 级 role → 422；
- 通过错误路径**访问已存在的记录**（GET / PATCH）→ 404，因为该资源不在这个作用域内；
- 列表不混用：Project 列表不含 Item 级引用，Item 列表只含本篇的 Item 级引用。

## 路径规则

只接受品牌源根目录下的**相对路径**。拒绝：

- 空字符串或纯空白；
- Windows 绝对路径（`C:\...`、`C:/...`）；
- Unix 绝对路径（`/...`）；
- UNC 路径（`\\server\...`、`//server/...`）；
- 任何 `..` 路径逃逸片段（含反斜杠写法 `a\..\..\x`，校验前先统一分隔符以免绕过）。

允许中文与空格。落库前统一规范化为 `/` 分隔、压缩重复分隔符、去掉开头的 `./`，因此 `a\b.md`、`a//b.md`、`./a/b.md` 视为同一路径。校验只做字符串层面判断，**不访问文件系统**。

**规范化后必须仍是非空的相对路径。** 校验规则直接作用于「最终会落库的那个值」，而不是原始输入：

- `""`、`"   "`、`"./"`、`"././"`、`"./././"` 以及 `.//.` 这类只由 `.` 组成的输入，一律 422 `errors.source_path`（`The source path must resolve to a non-empty relative path.`）。它们原始串非空、能通过全部基础安全检查，但规范化后会归零，若放行就会把 `source_path = ''` 写进库。
- 只由 `.` 段组成的路径（`.`、`././`）本质是目录引用而非来源文件，同样按空处理。
- `./a/b.md` 仍然**合法**，落库为 `a/b.md`——拒绝的是「规范化后为空」，不是禁止正常的 `./` 前缀。

规范化只有一份实现：Request 的 `normalisePath()` 既是校验依据，也通过 `canonicalSourcePath()` 提供给 Controller 落库，Controller 不再维护第二套规则，因此「校验的字符串」与「存入的字符串」不可能漂移。

## 重复规则

数据库允许同一路径被多个 ContentItem 共享（正式能力），因此**没有**全局 `source_path` 唯一约束。HTTP API 层判定重复的口径是：

> 同一 scope（同一个 Project 级 / 同一个 ContentItem） + 同一 role + 同一规范化路径

- POST 命中重复 → 422 `errors.source_path`；
- PATCH 改成与既有记录重复 → 422（排除自身）；
- 同一 scope 下**不同 role** 的相同路径 → 允许；
- 同一路径被**不同 ContentItem** 引用 → 允许；
- 同一 ContentItem 有**多条不同路径**的 `source_script` → 允许。

## API

响应统一 `{"data": ...}`，时间 UTC ISO 8601。

| 方法 | Project 级路径 | Item 级路径 |
| --- | --- | --- |
| GET | `/api/projects/{p}/sources` | `/api/.../items/{i}/sources` |
| POST | `/api/projects/{p}/sources` | `/api/.../items/{i}/sources` |
| GET | `/api/projects/{p}/sources/{ref}` | `/api/.../items/{i}/sources/{ref}` |
| PATCH | `/api/projects/{p}/sources/{ref}` | `/api/.../items/{i}/sources/{ref}` |

**不提供 DELETE。**

POST / PATCH 请求体只接受 `role`、`source_path`、`note`。`id`、`project_id`、`content_item_id`、`authority` 一律 `prohibited`——归属由 URL 决定，authority 由服务端派生。

`SourceReferenceResource` 只返回真实列：`id`、`project_id`、`content_item_id`、`role`、`authority`、`source_path`、`note`、`created_at`、`updated_at`。**不返回** `file_exists`、`absolute_path`、`sync_status`、`hash`、`version`——这些字段不存在。

## ProjectContext

Session 当前 Project 是服务端作用域事实，URL 不得自动切换 Session：

- 任何 scope 读取出现 404 → Toast 提示后 `router.visit('/projects')`；
- 绝不根据 URL 调用 `selectProject`；
- Item 级请求逐层验证 `Project → ContentColumn → Topic → ContentItem`，任一错配 404。

页面沿用 DEV-W06.1 的错误处理分工：**加载失败**才切 ErrorState，**写操作失败（含 422）只 Toast**，业务门禁被拒时页面保持当前状态、用户可继续修改。422 优先显示服务端第一条真实错误（`firstErrorMessage()`）。

### 切换项目后的落点

来源是项目级作用域。若用户停留在 `/projects/OLD/sources`（或 OLD 篇目的 Item Sources）却在顶部切换到 NEW 项目，Session 已经是 NEW，但 URL 与页面仍显示 OLD 的记录，随后任何写操作都会因为「URL 的 OLD + Session 的 NEW」而 404——这是应当避免的 stale UI。

因此 `AdminLayout.onSwitch()` 在 `await selectProject(id)` 成功之后，额外把来源页面带到新项目：

- Project Sources：`/projects/OLD/sources` → `/projects/NEW/sources`
- Item Sources：同样进入 `/projects/NEW/sources`

Item Sources 切换后**不猜测**新项目的 Column / Topic / Item——这些层级 ID 在新项目里没有可安全推导的关系，统一落到项目级来源页是 Lite 阶段的正确行为。

顺序原则不变：先 `await selectProject(id)` 让服务端 Session 切换成功，再 `router.visit(...)`；绝不根据 URL 自动 selectProject，也不先跳 URL 再切 Session。Topic / Item / Production 等页面的切换落点本轮**不**改动。

## UI

| 页面 | 路由 | 说明 |
| --- | --- | --- |
| Project Sources | `/projects/{project}/sources` | `Sources/ProjectSources.vue`，route name `sources.project` |
| Item Sources | `/projects/{project}/columns/{c}/topics/{t}/items/{i}/sources` | `Sources/ItemSources.vue`，route name `sources.item` |

两个页面路由都不带 `/api` 前缀，与 JSON API 不冲突。列表按角色分组显示角色、Authority 中文说明、相对路径与备注，支持新增与编辑；不提供删除、上传、打开文件或同步。

- `ContentItems/Index` 每行操作顺序：编辑文案 / 制作与渠道 / **来源** / 编辑；不按状态隐藏入口，历史导入的篇目同样可进入；
- `AdminLayout` 增加「来源管理」导航（与「小栏目」同级的按钮）。未选择项目时提示「请先选择一个项目」并跳 `/projects`；已选择时进入 `/projects/{current}/sources`，**不会**自动切换 Session Project。

前端新增 `SourceRole` / `SourceAuthority` / `SourceReference` 严格类型与中文标签，以及 `isItemScopedRole()` 镜像后端 `isContentItemScoped()`。表单在客户端也镜像相对路径规则以获得即时反馈，但服务端始终是最终 Gate。

## 验证

Feature 测试覆盖两级作用域的 list / create / update、role 与作用域错配（422 create 与 404 existing）、authority 与归属键伪造、绝对路径 / UNC / `..` 拒绝、反斜杠规范化、中文路径、四类重复规则、跨 Project 与错误祖先 404、以及无 DELETE 端点。页面路由另有 3 个渲染与命名测试。
