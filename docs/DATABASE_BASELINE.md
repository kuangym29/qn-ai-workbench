# 数据库基线

目标数据库为 MySQL 8.4 LTS，字符集 `utf8mb4`。Laravel migration 是 schema 的事实来源；本地自动测试使用内存 SQLite 验证基础约束与请求连通性。部署前需在 MySQL 8.4 再运行迁移验收。数据库时间统一按 UTC 存储，展示时按项目/用户时区转换，时区策略留后续明确。

## DEV-001 已建表

| 表 | 关键字段 | 约束 | 用途 |
| --- | --- | --- | --- |
| `projects` | `id`, `name`, `slug`, `description`, timestamps | `slug` 全局唯一 | 最高业务隔离边界 |
| `columns` | `id`, `project_id`, `name`, `slug`, `description`, `sort_order`, timestamps | `project_id` 外键；`(project_id, slug)` 唯一 | Project 内的小栏目 |

删除 Project 时数据库级联删除其 Column。产品层删除/归档策略尚未制定，开放删除操作前必须重新审查，不能直接暴露级联删除。`slug` 只作稳定路径标识，不作为权限凭据。当前不建立任何默认品牌数据，避免把青柠育见写成系统默认租户。

## 后续建模原则（未迁移）

Topic、Content Item、Production Task、Channel Task 必须可追溯 Project。跨表关联应校验同 Project，不能只依赖单字段外键。内容页、视觉资产及正式版本应独立于渠道任务；渠道任务引用共享资产并在必要时保存适配版。四类确认/验收/发布状态互相独立。这里是设计边界，不代表这些表已实现。
