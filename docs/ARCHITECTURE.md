# 架构基线

## 系统边界

技术栈：Laravel 13、MySQL 8.4 LTS、Vue 3、Inertia、TypeScript、Vite。Laravel 负责路由、校验、持久化及服务端授权；Inertia 连接服务端页面与 Vue。Vite 只负责前端构建。DEV-003 与 DEV-W02/W02.1 已将 Project / ContentColumn 工作台接入真实服务端数据。

正式业务层级：`Project → ContentColumn → Topic → ContentItem → ProductionTask → ChannelTask`。Project 是最高隔离边界；所有后续业务查询必须经当前 Project 限定，子对象 URL 也必须校验祖先归属。Project 选择在栏目和选题之前。不得从客户端传入的 `project_id` 单独推断访问权限。

## 内容生产原则

- Column、逐页内容、最终上图文案、查重、收尾句为跨品牌可复用能力。
- “2.5D 图文”是表现形式，不是独立内容类型或 Content Profile。
- 每篇只维护一套统一视觉母版，避免按渠道重复生图。
- 共享视觉资产至少区分无文案定稿底图、有文案定稿图；正式版本采用新增版本记录而非覆盖原文件。
- 公众号与视频号作为 Channel Task 引用同一 Content Item。公众号默认引用有文案定稿图，视频号默认引用无文案底图；只有确有需要才创建渠道适配版本。
- 文案确认、图稿验收、视频验收、实际发布必须分别记录和验收，不相互推断。

## 已建立的核心领域骨架

DEV-001 建立 Laravel/Inertia/Vue 启动页和 `projects`、旧名 `columns` 两张表。DEV-002 通过新迁移把后者改名为 `content_columns`，并建立 Topic、Content Item、Production Task、Channel Task。六个模型均显式归属 Project；复合外键保证 Topic 的 Column、Content Item 的 Topic/Column、Production Task 的 Content Item、Channel Task 的 Production Task 不会跨 Project 错配。一个 Content Item 在 Lite V1.0 对应一个共享 Production Task，多个 Channel Task 可引用它。

文案状态存于 Content Item，图稿状态存于 Production Task；视频状态和发布状态分别存于 Channel Task。渠道在数据库中使用字符串、在 PHP 中使用可扩展的 `Channel` 枚举控制，首批为 `wechat_official`、`wechat_channels`。当前无用户登录和项目授权流程；数据库约束保证记录关系一致，但不能替代服务端 Project 上下文及访问授权。

DEV-004 把四维状态统一为独立的 PHP string backed Enum，数据库继续存字符串。新建公众号任务的视频状态为 `not_applicable`，视频号为 `not_started`；二者发布状态均为 `unpublished`。文案、图稿、视频、发布的变化不得自动推进其他维度。旧状态通过独立 correction migration 回填，原 DEV-002 迁移保持不变。DEV-005 已实现 Topic、ContentItem 服务端 API，按 Session 当前 Project 与 URL 祖先链逐层限定；其业务编辑页及 ContentPage 尚未实现。创建 ContentItem 不自动创建 ProductionTask。具体路由、字段与错误契约见 `DEV-005_API_CONTRACT.md`。

### 未来共享视觉资产关系接口（DEV-002 不建表）

一个 Production Task 未来可关联多个共享视觉资产，首批角色为 `clean_master`（无文案定稿底图）和 `copy_master`（有文案定稿图）。资产记录必须与同一个 Project、Content Item、Production Task 对齐，后续迁移需用可验证的关联约束防止跨项目引用，并补对应测试。Channel Task 只引用共享资产，不复制独立母资产：公众号默认选 `copy_master`，视频号默认选 `clean_master`。渠道适配版可作为衍生资产，但不能覆盖共享母资产或正式版本。多页、多版本不应被 Production Task 上两个固定图片 ID 限制。真正的 Asset / AssetVersion / File 结构放到独立任务设计与实现。

## 架构风险与待决策点

1. **Project 访问授权尚未实现**：DEV-003 的会话当前 Project 约束请求范围，但选择接口目前可选择任何现有 Project，列表也列出全部 Project。多用户开放前必须确定身份及成员关系，并约束列表、选择和写入；当前作用域不等于访问授权。
2. **生产任务基数**：Lite V1.0 暂按一篇一套共享生产任务建唯一约束。若后续确需多轮独立生产任务，应先明确版本与历史保留方式，再迁移该约束。
3. **版本模型待细化**：正式版本不可覆盖，但版本标识、资产存储与渠道适配的具体表结构不在 DEV-002 定义，避免过早锁死。
4. **历史 fixtures 待对齐**：隔离区及豆包旧分支中的样本属于另一任务。导入前要核对其 Project 归属、状态语义、Column 表名及正式 schema。
5. **资产关系尚无数据库约束**：本轮仅定义未来接口。实现 Asset 表时必须为 Project / Content Item / Production Task 一致性及跨项目拒绝补测试，不能依赖字符串路径或可覆盖的固定字段。
6. **阶段顺序尚未由服务层约束**：Channel Task 的数据结构表示渠道衍生任务，但数据库无法判断共享生产是否已验收。后续创建渠道任务的服务须核对图稿验收状态，不能仅以存在 Production Task 推断生产完成。
