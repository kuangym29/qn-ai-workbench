# 架构基线

## 系统边界

技术栈：Laravel 13、MySQL 8.4 LTS、Vue 3、Inertia、TypeScript、Vite。Laravel 负责路由、校验、持久化及服务端授权；Inertia 连接服务端页面与 Vue。Vite 只负责前端构建。DEV-003 与 DEV-W02/W02.1 已将 Project / ContentColumn 工作台接入真实服务端数据。DEV-W03 已完成 Topic / ContentItem 业务编辑 UI。

正式业务层级：`Project → ContentColumn → Topic → ContentItem → ProductionTask → ChannelTask`。Project 是最高隔离边界；所有后续业务查询必须经当前 Project 限定，子对象 URL 也必须校验祖先归属。Project 选择在栏目和选题之前。不得从客户端传入的 `project_id` 单独推断访问权限。

## 内容生产原则

- Column、逐页内容、最终上图文案、查重、收尾句为跨品牌可复用能力。
- "2.5D 图文"是表现形式，不是独立内容类型或 Content Profile。
- 每篇只维护一套统一视觉母版，避免按渠道重复生图。
- 共享视觉资产至少区分无文案定稿底图、有文案定稿图；正式版本采用新增版本记录而非覆盖原文件。
- 公众号与视频号作为 Channel Task 引用同一 Content Item。公众号默认引用有文案定稿图，视频号默认引用无文案底图；只有确有需要才创建渠道适配版本。
- 文案确认、图稿验收、视频验收、实际发布必须分别记录和验收，不相互推断。

## 已建立的核心领域骨架

DEV-001 建立 Laravel/Inertia/Vue 启动页和 `projects`、旧名 `columns` 两张表。DEV-002 通过新迁移把后者改名为 `content_columns`，并建立 Topic、Content Item、Production Task、Channel Task。六个模型均显式归属 Project；复合外键保证 Topic 的 Column、Content Item 的 Topic/Column、Production Task 的 Content Item、Channel Task 的 Production Task 不会跨 Project 错配。一个 Content Item 在 Lite V1.0 对应一个共享 Production Task，多个 Channel Task 可引用它。

文案状态存于 Content Item，图稿状态存于 Production Task；视频状态和发布状态分别存于 Channel Task。渠道在数据库中使用字符串、在 PHP 中使用可扩展的 `Channel` 枚举控制，首批为 `wechat_official`、`wechat_channels`。当前无用户登录和项目授权流程；数据库约束保证记录关系一致，但不能替代服务端 Project 上下文及访问授权。

DEV-004 把四维状态统一为独立的 PHP string backed Enum，数据库继续存字符串。新建公众号任务的视频状态为 `not_applicable`，视频号为 `not_started`；二者发布状态均为 `unpublished`。文案、图稿、视频、发布的变化不得自动推进其他维度。旧状态通过独立 correction migration 回填，原 DEV-002 迁移保持不变。

DEV-005 已实现 Topic、ContentItem 服务端 API，按 Session 当前 Project 与 URL 祖先链逐层限定。创建 ContentItem 不自动创建 ProductionTask。具体路由、字段与错误契约见 `DEV-005_API_CONTRACT.md`。DEV-W03 已完成 Topic / ContentItem 业务编辑 UI。

### 内容页与版本模型（DEV-006A/B、DEV-007A、DEV-W04 已实现）

DEV-006A 完成 ContentPage / CopyRevision / PageVersion 数据模型设计；DEV-006B 完成核心数据层与不可变保护；DEV-007A 完成 Page / Revision API 与 confirmed 唯一入口；DEV-W04 完成文案编辑 UI 与 ProjectContext 修正。

正式版本模型三层结构：

- **ContentPage** = 稳定页面身份（`content_pages`）。每篇 ContentItem 下按 `page_no` 建立稳定页面，`page_type` 区分 `cover` / `content` / `column_closing` / `fixed_back_cover`。页面身份在文案迭代中保持不变。
- **ContentPageVersion** = append-only 页面文案版本（`content_page_versions`）。每页按 `version_no` 递增追加版本，不覆盖、不删除。Working Copy = 各 ContentPage 的最大 `version_no` 版本。
- **ContentCopyRevision** = 一次整篇正式确认事件（`content_copy_revisions`）。一次 confirm 为整篇所有页面创建对应 PageVersion 正式快照，`copy_revision_id` 关联到该次确认。

正式 Revision 与 Working Copy 的关系：

- 当前正式 Revision = 同 ContentItem 最大 `revision_no` 的 ContentCopyRevision。
- 不建立 `ContentItem.current_revision_id` 冗余字段，正式 Revision 通过最大 `revision_no` 查询确定。
- Formal Copy（已确认正式稿）= 对应 Revision 的完整 PageVersion 快照集合。
- Working Copy = 各 ContentPage 当前最大 `version_no` 的 PageVersion 集合。它可能是 `copy_revision_id != null` 的正式快照（确认后尚无新草稿），也可能是 `copy_revision_id = null` 的草稿。是否属于 Formal Copy 由 ContentCopyRevision 与 `copy_revision_id` 确定，不能仅凭是否为最大 `version_no` 判断。

进入正式 confirmed 的唯一入口：

- 普通 ContentItem API **不允许**直接将 `copy_status` 置为 `confirmed`。
- 正式入口为 `POST .../copy/confirm`。调用时必须：
  1. 创建新的 ContentCopyRevision（`revision_no` = 当前最大值 + 1）
  2. 为整篇所有 ContentPage 创建完整正式 PageVersion 快照（关联 `copy_revision_id`）
  3. 将 ContentItem 的 `copy_status` 更新为 `confirmed`
- Revision 创建后不可变，不允许修改或删除已确认的 PageVersion 正式快照。

### 未来共享视觉资产关系接口（DEV-002 不建表）

一个 Production Task 未来可关联多个共享视觉资产，首批角色为 `clean_master`（无文案定稿底图）和 `copy_master`（有文案定稿图）。资产记录必须与同一个 Project、Content Item、Production Task 对齐，后续迁移需用可验证的关联约束防止跨项目引用，并补对应测试。Channel Task 只引用共享资产，不复制独立母资产：公众号默认选 `copy_master`，视频号默认选 `clean_master`。渠道适配版可作为衍生资产，但不能覆盖共享母资产或正式版本。多页、多版本不应被 Production Task 上两个固定图片 ID 限制。真正的 Asset / AssetVersion / File 结构放到独立任务设计与实现。

### Source Reference 核心数据层（DEV-008A 已实现）

SourceReference 归属 Project，并可选择归属同 Project 的 ContentItem；Project 级台账的 `content_item_id` 为 null。五类 SourceRole 与四类 SourceAuthority 由 PHP string backed Enum 管理，复合外键阻止跨 Project 引用。路径保存为品牌源根目录下的相对路径，允许多个 Item 共享同一路径，也允许一篇引用多个脚本版本。当前不建 Column/Page 来源关系。DEV-008B 已实现青柠育见 4 篇 / 37 页历史文案与 11 条来源引用的预检式导入；详见 `DEV-008A_SOURCE_REFERENCE_IMPLEMENTATION.md`、`DEV-008B_YUJIAN_HISTORY_IMPORTER.md`。

## 架构风险与待决策点

1. **Project 访问授权尚未实现**：DEV-003 的会话当前 Project 约束请求范围，但选择接口目前可选择任何现有 Project，列表也列出全部 Project。多用户开放前必须确定身份及成员关系，并约束列表、选择和写入；当前作用域不等于访问授权。
2. **生产任务基数**：Lite V1.0 暂按一篇一套共享生产任务建唯一约束。若后续确需多轮独立生产任务，应先明确版本与历史保留方式，再迁移该约束。
3. **Source Reference 范围**：DEV-008B 导入器会检查历史 11 个本地来源文件并写入相对路径引用。SourceReference 的通用 HTTP API 与 UI 尚未实现；引用不表示源文件已上传或持续同步。
4. **历史 fixtures 待对齐**：隔离区及豆包旧分支中的样本属于另一任务。导入前要核对其 Project 归属、状态语义、Column 表名及正式 schema。
5. **资产关系尚无数据库约束**：本轮仅定义未来接口。实现 Asset 表时必须为 Project / Content Item / Production Task 一致性及跨项目拒绝补测试，不能依赖字符串路径或可覆盖的固定字段。
6. **阶段顺序尚未由服务层约束**：Channel Task 的数据结构表示渠道衍生任务，但数据库无法判断共享生产是否已验收。后续创建渠道任务的服务须核对图稿验收状态，不能仅以存在 Production Task 推断生产完成。
