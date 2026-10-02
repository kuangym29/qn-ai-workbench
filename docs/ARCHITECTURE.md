# 架构基线

## 系统边界

技术栈：Laravel 13、MySQL 8.4 LTS、Vue 3、Inertia、TypeScript、Vite。Laravel 负责路由、校验、持久化及服务端授权；Inertia 连接服务端页面与 Vue。Vite 只负责前端构建。DEV-003 与 DEV-W02/W02.1 已将 Project / ContentColumn 工作台接入真实服务端数据。DEV-W03 已完成 Topic / ContentItem 业务编辑 UI。

正式业务主链为 `Project → ContentColumn → Topic → ContentItem → ProductionTask → ChannelTask`；ContentItem 同时拥有 ContentPage / ContentCopyRevision / SourceReference，ProductionTask 进一步拥有共享视觉 `Asset → AssetVersion → File`。Project 是最高隔离边界；所有后续业务查询必须经当前 Project 限定，子对象 URL 也必须校验祖先归属。Project 选择在栏目和选题之前。不得从客户端传入的 `project_id` 单独推断访问权限。

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

DEV-009A 的 ProductionTask 通过可空 `copy_revision_id` 兼容旧任务；正式 API 创建时必须绑定该篇当前最大 `revision_no` 的 ContentCopyRevision。任务固定在该正式文案版本，后续确认新 Revision 不自动换版或重置图稿状态。仅显式 `use-current-copy` 可在无 ChannelTask 时切换并重置图稿为 `not_started`；批准图稿要求仍绑定当前正式 Revision。DEV-009A 已完成并合入 main。详见 `DEV-009A_PRODUCTION_TASK_API.md`。

### 渠道流程（DEV-W05 已实现服务端 API）

DEV-W05 把 ChannelTask 数据骨架升级为真实可执行的微信公众号与微信视频号工作流。渠道只引用共享 Production，不复制篇目与文案；渠道创建、视频终态、排期与正式发布都以「当前正式 Revision + 已验收共享图稿」为门禁，`artwork_status`、`video_status`、`publish_status` 三者互不推导。

`channel_tasks` 新增 `scheduled_at`（内部发布排期）与 `published_at`（实际发布确认），两列均可空、既有行迁移后为 null，数据库统一按 UTC 存储；客户端提交的 datetime 显式换算为 UTC 后落库。首次进入 `published` 时缺省用 `now()`，之后不可通过普通接口改写；已发布不允许退回 `unpublished`，也不允许整链 reset。

确认新正式 Revision 后，Production 与所有渠道状态都不会自动改动，Resource 的 `is_production_copy_current` 变为 `false`；stale 期间中间视频状态可保留，但视频 `approved`、发布 `scheduled` / `published` 一律拒绝。DEV-009A 的 `use-current-copy` 只覆盖无 ChannelTask 的情形，因此 DEV-W05 提供显式的 `restart-with-current-copy` 整链 reset：它保留 ChannelTask 的 id 与 channel，只重置状态与时间，且在任何渠道已发布时拒绝执行。

并发上统一采用 `ContentItem → ProductionTask → ChannelTask` 的行锁顺序，与 DEV-009A 一致。发布状态只是内部工作台记录，本轮不调用任何微信真实 API、不保存 access_token、不做自动发布，也不建模渠道专属文案。前端 Channel / Production UI、Asset 体系与 AI 生图 / 视频均不在本轮范围。详见 `DEV-W05_CHANNEL_TASK_API.md`。

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

### 共享视觉资产核心（DEV-010A 已实现）

DEV-010A 已正式建立 `ProductionTask → Asset → AssetVersion → File`。Asset 是某篇、某页、某角色的稳定逻辑槽位，首批角色只有 `clean_master`（无文案底图）和 `copy_master`（有文案图）；同一 ProductionTask + ContentPage + Role 只能有一个槽位，但同页两种角色可并存。Asset 不绑定 CopyRevision，因此 ProductionTask 显式换版后槽位身份继续存在。

AssetVersion 是 append-only 实际版本，每条都固定记录创建时依据的 `copy_revision_id` 与 `file_id`。ProductionTask 从 Revision 1 改绑 Revision 2 时，旧 AssetVersion 不修改、不删除；“当前 Production Revision 的资产候选版本”必须在 `copy_revision_id = ProductionTask.copy_revision_id` 的版本内再取最大 `version_no`，不能简单取 Asset 的全局最新版本。File 仅保存存储定位与图片元数据，本轮不负责上传、读取、哈希去重或对象存储。

数据库通过复合外键保证 Asset 的 Project / ContentItem / ProductionTask / ContentPage 对齐，并保证 AssetVersion 的 Asset / CopyRevision / File 与 Project、ContentItem 同域。AssetVersion 在 Model 层禁止 UPDATE / DELETE，Asset 的身份与 Role 也不可随意改归属。ChannelTask 尚未绑定具体 AssetVersion；公众号选择 `copy_master`、视频号选择 `clean_master` 的绑定与文件上传留给后续 Asset API / UI 任务。详见 `DEV-010A_SHARED_VISUAL_ASSET_CORE.md`。

### Source Reference（DEV-008A / DEV-008B / DEV-W07 已实现）

SourceReference 归属 Project，并可选择归属同 Project 的 ContentItem；Project 级台账的 `content_item_id` 为 null。五类 SourceRole 与四类 SourceAuthority 由 PHP string backed Enum 管理，Authority 始终由服务端根据 Role 派生；复合外键阻止跨 Project Item 引用。路径只保存品牌源根目录下的相对路径，不表示文件已上传或服务器能够访问本地磁盘。

DEV-008B 已实现青柠育见 4 篇 / 37 页历史文案与 11 条来源引用的预检式导入。DEV-W07 / W07.1 已补齐 Project 级与 Item 级 SourceReference API 和来源管理 UI：支持 list / show / create / update，不提供 DELETE、上传或 file_exists 检查；Project / Item Role 通过不同作用域 API 管理，错域创建 422、错域访问 404。相对路径统一规范化为 `/`，拒绝绝对路径、UNC、`..` 及规范化后为空的值；同一 scope + role + 规范化路径重复时 422，但相同路径可跨 Item 复用。Sources 页面切换 Project 时先更新 Session Project，再进入新项目 `/sources`。详见 `DEV-008A_SOURCE_REFERENCE_IMPLEMENTATION.md`、`DEV-008B_YUJIAN_HISTORY_IMPORTER.md`、`DEV-W07_SOURCE_REFERENCE_API_UI.md`。

### 查重语料基线（DEV-D08 已实现）

DEV-D08 / D08.1 / D08.2 已建立青柠育见 4 篇 / 37 页 Golden Dataset 与查重规则基线，PageType 分布为 cover 4 / content 25 / column_closing 4 / fixed_back_cover 4。正式规则分为 Source Representation Canonicalization → Exact Normalization → Overlap Normalization；Exact 与 Overlap 必须分层，Overlap Candidate 只用于候选发现，不能自动判定重复。V1.0 不使用 Vector DB / Embedding，字符 3-gram Jaccard 仅作为待更多真实语料校准的候选方法，最终由人工确认。视频号专属收尾句与共享 Formal Copy 保持隔离。详见 `DEV-D08_DUPLICATE_CLOSINGLINE_BASELINE.md`。

## 架构风险与待决策点

1. **Project 访问授权尚未实现**：DEV-003 的会话当前 Project 约束请求范围，但选择接口目前可选择任何现有 Project，列表也列出全部 Project。多用户开放前必须确定身份及成员关系，并约束列表、选择和写入；当前作用域不等于访问授权。
2. **生产任务基数**：Lite V1.0 暂按一篇一套共享生产任务建唯一约束。若后续确需多轮独立生产任务，应先明确版本与历史保留方式，再迁移该约束。
3. **Source Reference 仍只是引用管理**：DEV-W07 已有 API / UI，但它只保存相对路径，不上传、不同步、不验证服务器文件存在。未来若引入 Local Agent / 云文件同步，必须作为独立能力设计，不能把 SourceReference 偷换成文件对象。
4. **Asset 已有核心数据层但还没有写入 API / UI**：DEV-010A 已建立 File / Asset / AssetVersion 与复合约束；下一步需要定义安全的版本追加、当前 Revision 资产选择、File 元数据登记与工作台展示。ChannelTask 如何绑定具体 AssetVersion 仍未实现。
5. **查重阈值尚未校准**：DEV-D08 的 4 篇 / 37 页真实样本没有足够的自然 Overlap 案例；3-gram Jaccard 阈值只能作为初始研究值。生产查重必须保留 Exact / Overlap 分层、Golden Dataset 回归与人工最终确认。
6. **阶段顺序已由 DEV-W05 服务层约束**：Channel Task 的数据结构表示渠道衍生任务，创建渠道任务必须核对图稿验收状态与当前正式 Revision，不能仅以存在 Production Task 推断生产完成。已发布记录仍是普通 Publish API 的终态，不允许被普通 reset 抹掉。
