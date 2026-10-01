# 数据库基线

目标数据库为 MySQL 8.4 LTS，字符集 `utf8mb4`。Laravel migration 是 schema 的事实来源；本地自动测试使用内存 SQLite 验证基础约束与请求连通性。部署前需在 MySQL 8.4 再运行迁移验收。数据库时间统一按 UTC 存储，展示时按项目/用户时区转换，时区策略留后续明确。

## 核心表

| 表 | 关键字段 | 约束 | 用途 |
| --- | --- | --- | --- |
| `projects` | `id`, `name`, `slug`, `description`, timestamps | `slug` 全局唯一 | 最高业务隔离边界 |
| `content_columns` | `id`, `project_id`, `name`, `slug`, `description`, `sort_order`, timestamps | `project_id` 外键；`(project_id, slug)`、`(project_id, id)` 唯一 | Project 内的小栏目；由旧 `columns` 迁移改名 |
| `topics` | `id`, `project_id`, `content_column_id`, `title`, `description`, timestamps | `(project_id, content_column_id)` 复合外键 | 选题不得指向其他 Project 的栏目 |
| `content_items` | `id`, `project_id`, `content_column_id`, `topic_id`, `title`, `copy_status`, timestamps | `(project_id, content_column_id, topic_id)` 复合外键 | 篇目与其选题、栏目保持同 Project、同 Column |
| `production_tasks` | `id`, `project_id`, `content_item_id`, `artwork_status`, timestamps | `(project_id, content_item_id)` 复合外键；`content_item_id` 唯一 | 每篇一套共享生产任务 |
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

## 共享视觉资产的未来关系（未迁移）

Production Task 未来为共享视觉资产的一对多父节点。资产至少有 `clean_master`、`copy_master` 两类角色，且必须与父任务的 Project、Content Item 一致；渠道任务引用资产，公众号默认 `copy_master`，视频号默认 `clean_master`。衍生适配版和正式版本须独立记录，不覆盖母资产。未来 Asset / AssetVersion / File 迁移需设计复合关联并补跨 Project 拒绝测试；DEV-002 不建这些表，也不在 `production_tasks` 增加固定图片 ID。
