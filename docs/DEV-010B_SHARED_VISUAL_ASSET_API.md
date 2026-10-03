# DEV-010B｜Shared Visual Asset API

本任务开放 DEV-010A 已冻结数据层的三个同源 API。所有路径都在 `/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/assets` 下，先用 Session ProjectContext 和完整祖先链确认 ContentItem。无 ProductionTask 时读写均返回 404。

| Method | Path suffix | Result |
| --- | --- | --- |
| GET | `/` | 当前 ProductionTask 的 Asset Workspace，`{data: ...}` |
| GET | `/{asset}` | 当前 ProductionTask 的一个 Asset 槽位及所有历史版本，`{data: ...}` |
| POST | `/versions` | 原子登记 File、复用或创建 Asset 槽位并追加 AssetVersion，201 `{data: ...}` |

## Workspace 与历史

Workspace 字段：`production_task_id`、`copy_revision_id`、`copy_revision_no`、`is_copy_revision_current`、`pages`。legacy ProductionTask 的 `copy_revision_id = null` 时返回空 `pages`，POST 返回 422 `errors.production`。非空时，`pages` 严格取 pinned `ContentCopyRevision` 的正式 `ContentPageVersion` 快照，按 `page_no_snapshot` 升序；`page_no`、`page_type` 也来自快照。每页含 `content_page_id` 及 `assets.clean_master`、`assets.copy_master` 两个可空槽位。

AssetSlot 字段：`id`、`project_id`、`content_item_id`、`production_task_id`、`content_page_id`、`role`、`version_count`、`current_version`、`latest_version`、时间戳。`current_version` 是该槽位中匹配 ProductionTask 当前 pinned Revision 的最大 `version_no`；`latest_version` 是跨所有 Revision 的最大 `version_no`，两者可不同。详情额外返回 `versions`，按 `version_no` 降序，包含全部历史 Revision。AssetVersion 返回 `id`、`project_id`、`content_item_id`、`asset_id`、`copy_revision_id`、`copy_revision_no`、`version_no`、`note`、嵌套 `file` 与时间戳；File 仅返回数据库存储定位和元数据，不返回下载、预览或签名地址。

## 追加与校验

POST 仅接受 `content_page_id`、`role`、`storage_disk`、`storage_path`、`original_name`、`mime_type`、`size_bytes`、`width`、`height`、`note`。所有身份键、`copy_revision_id`、`version_no` 均由服务端决定，客户端传入即 422。角色由 `AssetRole` 控制。Page 必须属于 URL ContentItem，否则 404；属于本篇但没有 pinned Revision 正式快照则 422 `errors.content_page_id`。Production stale 仍可追加其 pinned Revision 的资产，不自动切换 Revision，也不更改 `artwork_status` 或既有验收 Gate。

`storage_disk` 限 80 字符及字母、数字、点、下划线、短横线。`storage_path` 限 640 字符，先 trim，再把反斜杠改为正斜杠、合并重复斜杠、移除连续开头 `./` 和两端斜杠；原输入若为 Windows、Unix、UNC 绝对路径，或规范化后为空、含 `..` 路径段，均拒绝。`original_name` 必须是明确提交的单一文件名。API 不访问磁盘或读取图片。

同 Project 的 `(storage_disk, canonical storage_path)` 命中已有 File 时，仅当 `original_name`、`mime_type`、`size_bytes`、`width`、`height` 完全一致才复用；冲突返回 422 `errors.storage_path`，不覆写元数据。Asset 槽位按 `(ProductionTask, ContentPage, role)` 复用。事务中按 `ContentItem → ProductionTask → Asset` 顺序加锁，并分配同槽位 `max(version_no)+1`；数据库唯一约束作为最终竞争保护，唯一冲突转换为可重试的 422。AssetVersion 保留旧 Revision 来源，追加时不会更新历史版本。

本任务没有 PATCH/DELETE、文件上传、Storage 写入、图片预览、对象存储、Channel Asset Binding、自动 Artwork 状态迁移或资产完整度批准 Gate。验证结果以 DEV-010B 交付报告为准。
