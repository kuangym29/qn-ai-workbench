# 数据库基线

目标数据库为 MySQL 8.4 LTS，字符集 `utf8mb4`。Laravel migration 是 schema 的事实来源；本地自动测试使用内存 SQLite 验证基础约束与请求连通性。部署前需在 MySQL 8.4 再运行迁移验收。数据库时间统一按 UTC 存储，展示时按项目/用户时区转换，时区策略留后续明确。

## 核心表

| 表 | 关键字段 | 约束 | 用途 |
| --- | --- | --- | --- |
| `projects` | `id`, `name`, `slug`, `description`, timestamps | `slug` 全局唯一 | 最高业务隔离边界 |
| `content_columns` | `id`, `project_id`, `name`, `slug`, `description`, `sort_order`, timestamps | `project_id` 外键；`(project_id, slug)`、`(project_id, id)` 唯一 | Project 内的小栏目；由旧 `columns` 迁移改名 |
| `topics` | `id`, `project_id`, `content_column_id`, `title`, `description`, timestamps | `(project_id, content_column_id)` 复合外键 | 选题不得指向其他 Project 的栏目 |
| `content_items` | `id`, `project_id`, `content_column_id`, `topic_id`, `title`, `copy_status`, timestamps | `(project_id, content_column_id, topic_id)` 复合外键 | 篇目与其选题、栏目保持同 Project、同 Column |
| `production_tasks` | `id`, `project_id`, `content_item_id`, 可空 `copy_revision_id`, `artwork_status`, timestamps | `(project_id, content_item_id)` 与 `(project_id, content_item_id, copy_revision_id)` 复合外键；`content_item_id` 唯一 | 每篇一套共享生产任务，正式 API 固定绑定制作依据的文案 Revision |
| `channel_tasks` | `id`, `project_id`, `production_task_id`, `channel`, `video_status`, `publish_status`, timestamps | `(project_id, production_task_id)` 复合外键；`(production_task_id, channel)` 唯一 | 渠道任务引用共享生产，不复制篇目 |

删除 Project 时相关表目前按数据库外键级联删除。产品层删除/归档策略尚未制定，开放删除操作前必须重新审查，不能直接暴露级联删除。`slug` 只作稳定路径标识，不作为权限凭据。当前不建立任何默认品牌数据。

`copy_status`、`artwork_status`、`video_status`、`publish_status` 分开存储，不以一个 `completed` 推断其他阶段。四列继续使用字符串，PHP Model 分别 cast 到 string backed Enum，不使用数据库 ENUM。

| 维度 | 合法值 | 新记录默认值 |
| --- | --- | --- |
| Copy（ContentItem） | `not_started`、`editing`、`pending_confirmation`、`confirmed` | `not_started` |
| Artwork（ProductionTask） | `not_applicable`、`not_started`、`in_progress`、`pending_review`、`approved` | `not_started` |
| Video（ChannelTask） | `not_applicable`、`not_started`、`in_progress`、`pending_review`、`approved` | 公众号 `not_applicable`；视频号 `not_started` |
| Publish（ChannelTask） | `unpublished`、`scheduled`、`published` | `unpublished` |

`video_status` 正式为非空：数据库默认 `not_applicable`，视频号 Factory 明确设为 `not_started`。DEV-004 correction migration 先回填旧数据的 `draft → not_started`、`not_published → unpublished`、`video_status = null → not_applicable`，再更新列默认值与非空约束。回滚仅恢复旧列定义，不反向改写已转换的数据；重新运行可安全重复回填。SQLite 变更列定义时暂时关闭外键检查以避免表重建引发级联删除；MySQL 8.4 保持正常外键检查。

`channel` 在数据库中是字符串、在 PHP Model 中受 `Channel` 枚举控制；当前值为 `wechat_official` 和 `wechat_channels`。新增渠道需新增代码枚举值，不需要改数据库 Enum。

DEV-009A 新增迁移在 `production_tasks` 增加可空 `copy_revision_id`，保留旧未绑定任务；复合外键 `(project_id, content_item_id, copy_revision_id)` 指向 `content_copy_revisions(project_id, content_item_id, id)` 的复合唯一键，删除被引用 Revision 时采用 restrict。新 API 创建必须绑定本篇当前正式 Revision；文案确认新 Revision 不自动更换任务绑定。正式文案历史原有的 restrict 外键已限制 Project / ContentItem 硬删除，当前也没有公开删除 API。详见 `DEV-009A_PRODUCTION_TASK_API.md`。

## 内容页与版本表（DEV-006B 已实现）

以下三张表由 DEV-006B 创建，构成 ContentPage 稳定身份与 append-only 文案版本模型。

### content_pages

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint, PK | 页面稳定身份 |
| `project_id` | foreignId, restrictOnDelete | 归属 Project |
| `content_item_id` | unsignedBigInteger | 所属 ContentItem |
| `page_no` | unsignedBigInteger | 页码（从 1 开始） |
| `page_type` | string(40) | 页面类型：`cover` / `content` / `column_closing` / `fixed_back_cover` |
| timestamps | | |

**约束**：
- unique(`content_item_id`, `page_no`) → 同篇目内页码唯一
- unique(`project_id`, `content_item_id`, `id`) → Project 作用域内 ID 唯一
- foreign(`project_id`, `content_item_id`) references content_items(`project_id`, `id`), restrictOnDelete

### content_copy_revisions

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint, PK | Revision ID |
| `project_id` | foreignId, restrictOnDelete | 归属 Project |
| `content_item_id` | unsignedBigInteger | 所属 ContentItem |
| `revision_no` | unsignedBigInteger | 版本号（同 ContentItem 内递增） |
| `confirmed_at` | timestamp | 正式确认时间 |
| timestamps | | |

**约束**：
- unique(`content_item_id`, `revision_no`) → 同篇目内版本号唯一
- unique(`project_id`, `content_item_id`, `id`) → Project 作用域内 ID 唯一
- foreign(`project_id`, `content_item_id`) references content_items(`project_id`, `id`), restrictOnDelete

**正式 Revision 定义**：当前正式 Revision = 同 ContentItem 最大 `revision_no`。不建立 `content_items.current_revision_id` 冗余字段。

### content_page_versions

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint, PK | PageVersion ID |
| `project_id` | foreignId, restrictOnDelete | 归属 Project |
| `content_item_id` | unsignedBigInteger | 所属 ContentItem |
| `content_page_id` | unsignedBigInteger | 所属 ContentPage |
| `copy_revision_id` | unsignedBigInteger, nullable | 关联的正式 Revision（草稿版本为 null） |
| `version_no` | unsignedBigInteger | 页面版本号（同 Page 内递增） |
| `page_no_snapshot` | unsignedBigInteger, nullable | 确认时页码快照 |
| `page_type_snapshot` | string(40), nullable | 确认时页面类型快照 |
| `column_label` | text, nullable | 栏目名 |
| `cover_title` | text, nullable | 封面标题 |
| `cover_subtitle` | text, nullable | 封面副标题 |
| `page_title` | text, nullable | 内容页标题 |
| `page_small_text` | text, nullable | 内容页小字 |
| `closing_line` | text, nullable | 收尾句 |
| `note` | text, nullable | 备注 |
| timestamps | | |

**约束**：
- unique(`content_page_id`, `version_no`) → 同页面内版本号唯一
- unique(`copy_revision_id`, `content_page_id`) → 同一次正式确认内每页只有一个正式快照
- foreign(`project_id`, `content_item_id`, `content_page_id`) references content_pages(`project_id`, `content_item_id`, `id`) 的复合唯一键（composite unique key）, restrictOnDelete
- foreign(`project_id`, `content_item_id`, `copy_revision_id`) references content_copy_revisions(`project_id`, `content_item_id`, `id`) 的复合唯一键（composite unique key）, restrictOnDelete

**版本模型核心规则（V1.1 校正 Working Copy 定义）**：

- **Working Copy** = 各 ContentPage 当前最大 `version_no` 的 PageVersion。
  - 它可能是**正式快照**（`copy_revision_id != null`）——例如刚完成正式确认且之后尚未新建草稿。
  - 它也可能是**草稿**（`copy_revision_id = null`）——例如正式确认后又保存了新草稿。
  - **是否属于正式稿，不能通过"是不是当前最大版本"判断。**

- **Formal Copy** = 对应 ContentCopyRevision 及其 `copy_revision_id` 对应的完整 PageVersion 快照集合。
  - 正式稿的判定依据是 `copy_revision_id` 是否指向某个 ContentCopyRevision，而不是版本号大小。

- PageVersion append-only：不覆盖、不删除。
- 进入 confirmed 的唯一入口：`POST .../copy/confirm`，必须同时创建 ContentCopyRevision + 完整 PageVersion 正式快照。

## 共享视觉资产的未来关系（未迁移）

Production Task 未来为共享视觉资产的一对多父节点。资产至少有 `clean_master`、`copy_master` 两类角色，且必须与父任务的 Project、Content Item 一致；渠道任务引用资产，公众号默认 `copy_master`，视频号默认 `clean_master`。衍生适配版和正式版本须独立记录，不覆盖母资产。未来 Asset / AssetVersion / File 迁移需设计复合关联并补跨 Project 拒绝测试；DEV-002 不建这些表，也不在 `production_tasks` 增加固定图片 ID。

## Source Reference（DEV-008A 核心数据层已实现）

`source_references` 包含 `id`、`project_id`、可空 `content_item_id`、`role` string(40)、`authority` string(40)、`source_path` string(1000)、可空 `note` text、timestamps。Role / Authority 在 PHP 层 cast 到 string backed Enum，不使用数据库 ENUM 或 Role/Scope CHECK。普通索引为 `(project_id, role)` 与 `(project_id, content_item_id, role)`；`source_path` 不唯一、不建索引。

`project_id` 外键与 `(project_id, content_item_id)` → `content_items(project_id, id)` 复合唯一键均级联删除。后者在 `content_item_id = null` 时允许 Project 级引用，在非空时拒绝跨 Project Item。Project 删除会清理所有来源引用；ContentItem 删除只清理其 Item 级引用。两种数据库的迁移、回滚重跑和删除测试已验证。未建立 `content_column_id`、`content_page_id`、polymorphic 关系或路径唯一约束。DEV-008B 历史 Importer 已实现：四篇来源以相对路径写入 11 条引用，文件存在性和 D06 对照在写入前检查。通用 API / UI 尚未实现。详见 `DEV-008A_SOURCE_REFERENCE_IMPLEMENTATION.md`、`DEV-008B_YUJIAN_HISTORY_IMPORTER.md`。
