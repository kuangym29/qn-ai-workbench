# DEV-011A｜Channel Asset Binding Core + API

渠道只引用共享 `AssetVersion`，不复制图片或 File。公众号由 `Channel::expectedAssetRole()` 选择 `copy_master`；视频号选择 `clean_master`。本任务没有上传、预览、渠道资产副本或新的状态列。

## Schema 与历史

新表 `channel_asset_bindings` 保存 `project_id`、`content_item_id`、`production_task_id`、`channel_task_id`、`content_page_id`、`asset_id`、`asset_version_id`、`copy_revision_id`、`binding_no` 和时间戳。没有 `role`、`channel`、`status` 或 `note`。同一 `(channel_task_id, content_page_id)` 的 `binding_no` 从 1 递增，唯一键防止并发重复编号。Model 拒绝 UPDATE 和 DELETE；再次选择必须追加新行。

新增复合唯一键：`channel_tasks(project_id, production_task_id, id)`、`assets(project_id, content_item_id, production_task_id, content_page_id, id)`、`asset_versions(project_id, content_item_id, asset_id, copy_revision_id, id)`。Binding 通过这三个复合键分别引用 ChannelTask、Asset 和 AssetVersion，数据库拒绝跨 Project、错 Item/Production/Page/Asset/Revision/Version。外键采用 restrictOnDelete；两个 additive migration 按反向依赖顺序回滚，不修改历史 migration。

## API 契约

完整前缀：`/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels/{channel}/assets`。仅新增：

| Method | Path | 结果 |
| --- | --- | --- |
| GET | 前缀 | `{data: ChannelAssetWorkspace}` |
| POST | 前缀 + `/bindings` | 201 `{data: ChannelAssetBinding}` |

`{channel}` 使用渠道枚举字符串。每次请求验证 Session Project 与完整 Project → Column → Topic → Item → Production → Channel 链；不存在或错域为 404。POST 只接受 `content_page_id`、`asset_version_id`，客户端提供其他归属或版本编号即 422。页面必须属于当前 Item 且存在于 Production pinned Revision 的正式快照；版本必须来自同 Production、同页、该渠道预期角色及同 pinned Revision。跨 Project/Item/Production 的版本为 404；同域错页、错角色或错 Revision 为 422。

Workspace 顶层：`channel_task_id`、`channel`、`expected_asset_role`、`production_task_id`、`copy_revision_id`、`copy_revision_no`、`is_production_copy_current`、`is_complete`、`bound_page_count`、`total_page_count`、`pages`。页面由 pinned Revision 的 `ContentPageVersion` 正式快照按 `page_no_snapshot` 排序，页码和类型也取快照。每页给出 `content_page_id`、`page_no`、`page_type`、`asset_id`、`available_versions`、`current_binding`、`latest_binding`。`available_versions` 只含该页预期角色且属于 pinned Revision 的 AssetVersion，版本号降序。`current_binding` 取同 Channel/Page/pinned Revision 的最大 `binding_no`；`latest_binding` 取该 Channel/Page 的最大历史 `binding_no`，可能属于旧 Revision。Binding Resource 包含 `id`、全部归属 ID、`copy_revision_no`、`binding_no`、已有 AssetVersionResource 嵌套及 `created_at`。

## 完整度与终态

`total_page_count` 是 pinned Revision 快照页数；`bound_page_count` 是存在当前 Revision Binding 的页数。仅当总页数大于 0 且全部页面有当前 Binding，`is_complete` 才为 true。legacy null Revision 返回零页且不完整。Production stale 时可继续绑定旧 pinned Revision，历史来源仍清楚；重启到新 Revision 保留全部旧 Binding，它们自然不再属于 `current_binding`，完整度重新变 false。

渠道创建不要求绑定完整。公众号进入 `scheduled` 或 `published`、视频号进入 `VideoStatus::Approved` 或发布终态时，在原门禁之外要求完整绑定；失败返回 422 对应 `publish_status` 或 `video_status`。中间视频状态不要求完整。已发布再次提交 `published` 且没有新发布时间仍保持原 no-op，不因后来绑定变化改写发布历史。POST Binding 本身不改变 Artwork、Video、Publish 或排期/发布时间。

绑定追加在事务内按 ContentItem → ProductionTask → ChannelTask 锁顺序执行，随后分配该 Channel/Page 的 `max(binding_no)+1`。数据库唯一约束作为最终并发保护，冲突转换为可重试的 422。测试结果以交付报告为准。
