# DEV-010A｜Shared Visual Asset 核心数据层

本任务只建立 `ProductionTask → Asset → AssetVersion → File` 的持久化骨架。Asset 是某篇、某页、某角色的稳定逻辑槽位；AssetVersion 是 append-only 的实际版本；File 保存存储定位和元数据，不负责上传或读取文件。没有增加第二套图稿状态机，图稿验收状态仍在 ProductionTask。

## Schema 与版本语义

- `production_tasks` 新增复合唯一键 `(project_id, content_item_id, id)`，作为 Asset 的作用域外键目标。不改历史迁移；一篇一个 ProductionTask 的现有唯一键保持。
- `files`：`id`、`project_id`、`storage_disk` string(80)、`storage_path` string(640)、`original_name` string(255)、可空 `mime_type`、`size_bytes`、`width`、`height`、时间戳。`(project_id, id)` 和 `(project_id, storage_disk, storage_path)` 唯一。路径长度采用 640 字符，因为在 MySQL 8.4 的 utf8mb4 下，80 + 1000 字符的完整唯一索引会超过 InnoDB 索引长度上限；640 字符保留完整的数据库唯一性，不采用有碰撞风险的前缀索引。
- `assets`：`id`、`project_id`、`content_item_id`、`production_task_id`、**非空** `content_page_id`、`role` string(40)、时间戳。`AssetRole` 是 PHP string backed Enum，仅有 `clean_master`（无文案底图）和 `copy_master`（有文案图）。`(production_task_id, content_page_id, role)` 唯一；同一页可各有一槽，但同角色不重复。`(project_id, content_item_id, id)` 唯一，供版本表引用。Asset 没有 `copy_revision_id`，因此 ProductionTask 换正式文案时槽位身份不变。
- `asset_versions`：`id`、`project_id`、`content_item_id`、`asset_id`、非空 `copy_revision_id`、非空 `file_id`、`version_no` unsignedBigInteger、可空 `note`、时间戳。`(asset_id, version_no)` 唯一；不增加 `current_version_id`、`status`、文案或图片固定字段。每次追加版本必须固定记录当时依据的正式 CopyRevision，ProductionTask 后续显式改绑时不改旧版本。

当前 Asset 版本是该槽位 `version_no` 最大的版本。但**当前 Production Revision 的资产候选版本**是该槽位中 `copy_revision_id = ProductionTask.copy_revision_id` 的版本，再取其中最大 `version_no`；不能简单使用全局最大版本。版本号应按同一 Asset 的 1、2、3…追加；本轮无 API，数据库唯一约束防重复，后续写入服务须在事务中分配连续序号。

## 作用域与删除

Asset 通过 `(project_id, content_item_id, production_task_id)` 复合外键指向 ProductionTask，并通过 `(project_id, content_item_id, content_page_id)` 指向 ContentPage。AssetVersion 分别通过 `(project_id, content_item_id, asset_id)` 指向 Asset、`(project_id, content_item_id, copy_revision_id)` 指向正式 Revision、`(project_id, file_id)` 指向 File。全部依据现有或新增的复合唯一键，数据库拒绝跨 Project、同 Project 错 Item 和跨页关系。

新表的 Project 外键及 Asset/Version/File 关联采用 `restrictOnDelete`：File、Revision 或 Asset 被版本引用时不能直接删除；存在资产历史的 Project、ContentItem、ProductionTask、ContentPage 也不能被硬删。本轮不开放删除 API。AssetVersion 创建后 Model 层拒绝 UPDATE 和 DELETE；Asset 创建后 Model 层拒绝更改 Project、Item、ProductionTask、Page 和 Role。原生 SQL 可绕过 Model 事件，数据库仍通过外键防止引用悬空。File 本轮只记录元数据，不做内容哈希去重；存储路径的更新策略应在后续 File 写入服务开放前明确。

## 边界与验收

ProductionTask 上不加 `clean_master_file_id` / `copy_master_file_id`，因为一篇有多页、每页两种角色、每种角色可有多个历史版本。ChannelTask 暂无 AssetVersion 绑定；公众号选择 `copy_master`、视频号选择 `clean_master` 的具体引用策略留给 DEV-010B/010C。DEV-W05 的显式 restart 只改变 ProductionTask 当前 Revision 和阶段状态，不删除 Asset 或旧 AssetVersion。本任务未实现文件上传、Storage 写入、对象存储、文件下载、图像生成、视频生成、渠道发布或 Asset HTTP/UI。

SQLite 自动测试验证两种角色共存、重复槽位拒绝、所有复合外键错域拒绝、同一路径唯一、版本号唯一、Revision 1 → 2 后旧版本保留、模型不可变和被引用对象的删除限制；另对四个迁移执行 clean migrate、依赖顺序 rollback 与 re-run。MySQL 8.4 的实际迁移和目标测试结果以任务验收报告为准。
