# DEV-006B｜ContentPage 与 CopyRevision 核心数据层实现说明

本任务仅实现数据结构、领域关系、Factory、领域服务与测试。正式业务层级继续以 Project 为最高隔离边界；不增加 Content Profile、HTTP API、Vue、Importer、Source Reference、渠道专属文案或资产表。设计依据：`DEV-006A_CONTENT_PAGE_DESIGN.md` 的已审核三表完整快照结论。

## 数据结构

- `content_pages`：稳定页身份，`project_id`、`content_item_id`、`page_no`、`page_type`；同篇 `page_no` 唯一，`(project_id, content_item_id)` 复合外键指向 ContentItem。
- `content_copy_revisions`：一次整篇正式确认，`revision_no` 从 1 起逐篇递增，仅保存正式修订与确认时间。当前正式稿为该篇最大 `revision_no`；ContentItem 不增加指针。
- `content_page_versions`：逐页追加文案，`copy_revision_id = null` 为草稿，非空为正式快照。复合外键同时约束 Page 与 Revision 必须属于相同 Project 和 ContentItem；`(content_page_id, version_no)`、`(copy_revision_id, content_page_id)` 唯一。MySQL 与 SQLite 均允许多个 null 修订 ID 的草稿。
- `PageType` 是 PHP string backed Enum：`cover`、`content`、`column_closing`、`fixed_back_cover`；数据库继续存 string。

三张表的迁移均为新增文件，未改历史 Migration。页面的 Column/Topic 通过 ContentItem 推导。数据库外键限制删除有历史引用的页或修订；本轮不开放页面删除。

## 领域操作

`ContentCopyService::appendDraft(ContentPage, array)` 锁定 ContentItem 与 Page，在事务中复制上一版本的文案字段并覆盖本次提交字段，插入 `version_no + 1` 的新草稿；原版本不更新。ContentItem 文案状态转为 `editing`。

`confirmContentItem(ContentItem)` 锁定篇目及页面，先验证页面连续为 1…N、类型合法、每页已有版本且文案符合类型的最小规则，再在同一事务内创建一个整篇 Revision 和所有页面的**完整**正式快照；未修改页面也追加版本。全部成功后才把 `copy_status` 设为 `confirmed`。失败不会留下部分 Revision 或页版本。固定封底允许全部正文文案为 null。

`reorderPages(ContentItem, array $pageIds)` 要求完整且无重复的本篇 Page ID 顺序，锁定篇目后先写入位于当前最大页号之后的安全临时区间，再写最终 1…N；历史正式版本的 `page_no_snapshot` 不变。若已确认的篇目发生重排，文案状态回到 `editing`。

所有已持久化 PageVersion 只能追加，Model 层拒绝 UPDATE；正式版本还拒绝 DELETE。判定正式删除时使用数据库原始的 `copy_revision_id`，不能靠先改成 null 绕过。原生 SQL 可绕过 Eloquent 事件，因此数据库账号权限和后续写路径仍需约束。

## 验收与后续边界

独立测试覆盖三表复合外键、唯一约束、可空草稿修订、模型关系、Factory 归属、版本递增、两次完整确认、未修改页复制、旧正式稿不变、失败回滚、正式版不可变、重排与 10/9/10/8 页结构。历史真实文案未写入测试或数据库。

MySQL 8.4 验收使用独立临时库执行 clean migrate、三表 rollback/re-run 和领域测试，结束后删除临时库；SQLite 由标准测试套件验证。本轮不实现 Source Reference、Channel override、HTTP API、前端 UI、历史导入或页面删除。下一任务如需开放写 API，应先基于当前 ProjectContext 做祖先作用域与输入校验，不能直接暴露 Service 给客户端。
