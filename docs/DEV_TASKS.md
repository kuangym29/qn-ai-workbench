# 开发任务清单

## DEV-001：项目初始化与开发基线（已完成）

- Laravel 13、Vue 3、Inertia、TypeScript、Vite 工程与最小启动页。
- MySQL 8.4 环境示例；Project/Column migration 草案。
- PHP 测试、前端类型检查与构建命令；README 本地启动说明。
- 四份统一开发文档。完成后在 `codex/DEV-001-bootstrap` 提交，等待审核，不直接合并 `main`。

验收：`php artisan test`、`vendor/bin/pint --test`、`npm run typecheck`、`npm run build`；有 MySQL 8.4 实例时另跑 `php artisan migrate`。

## DEV-002：核心领域模型与数据库骨架（已完成）

按本轮指令建立 `projects`、`content_columns`、`topics`、`content_items`、`production_tasks`、`channel_tasks` 的 Migration、Model、关系、Factory 和项目作用域测试。通过复合外键阻止跨 Project 与跨 Column 错误引用；保持文案、图稿、视频、发布状态独立。仅记录未来共享视觉资产的关系约定，不建 Asset / File / AssetVersion 表。本轮不开发业务 API、权限、查重或发布接口，完成后测试并提交，不合并 `main`。

此前 DEV-002 的 Project 进入流程建议已被本轮明确范围取代；该流程留给后续独立任务重新排期。

## DEV-003：Project / ContentColumn 服务端业务与数据契约（已完成并合入 main）

实现 Project 列表、创建、编辑、会话中的当前 Project，及当前 Project 内 ContentColumn 列表、创建、读取、编辑。使用 Request Validation 和服务端 Project Scope 拒绝跨 Project 请求；稳定接口见 `DEV-003_API_CONTRACT.md`。此阶段无用户成员授权，不能将会话 Project 当作用户权限。运行 PHP 测试、MySQL 8.4 迁移、Pint、前端类型检查及构建后提交，不合并 `main`。

## DEV-W02 / W02.1：后台 UI 接口集成与作用域体验修正（已完成并合入 main）

Project / ContentColumn 工作台接入 DEV-003 真实接口，并修正 Project / Column 作用域编辑及 404 处理体验。

## DEV-004：四维状态体系正式收口（已完成并合入 main）

新增 Copy、Artwork、Video、Publish 四个 string backed Enum；用独立 correction migration 回填旧状态并更新数据库默认值，保持 DEV-002 历史迁移不变。更新 Model cast、Factory、领域测试及架构文档。此任务不开发 Topic / ContentItem API 或 UI、ContentPage、资产、导入器及生产流程。

## DEV-005：Topic / ContentItem 服务端业务与 API（已完成并合入 main）

基于正式 Main 的独立工作区，实现 Topic 与 ContentItem 的列表、创建、读取和编辑；沿用 Session ProjectContext，逐层验证 Project / ContentColumn / Topic / ContentItem 的归属，拒绝客户端伪造归属键。ContentItem 使用 DEV-004 的 CopyStatus，创建时不自动创建 ProductionTask。契约见 DEV-005_API_CONTRACT.md。本轮不开发 UI、ContentPage、Importer 或生产/渠道 API。已完成服务端 API、作用域校验、Feature 测试与数据契约；前端 UI 留给后续独立任务。

## DEV-W03：Topic / ContentItem 业务编辑 UI（已完成并合入 main）

Topic / ContentItem 工作台接入 DEV-005 真实接口，实现列表、创建、编辑等业务编辑 UI。

## DEV-006A：ContentPage / CopyRevision / PageVersion 数据模型设计（已完成并合入 main）

完成 ContentPage 稳定身份、ContentPageVersion append-only 版本、ContentCopyRevision 整篇正式确认三层模型的设计文档与约束定义。明确 Working Copy / Formal Copy 关系，以及 confirmed 唯一入口原则。设计文档见 `DEV-006A_CONTENT_PAGE_DESIGN.md`。

## DEV-006B / 006B.1：ContentPage 核心数据层与不可变保护（已完成并合入 main）

实现 `content_pages`、`content_copy_revisions`、`content_page_versions` 三张表的 Migration、Model、关系、复合外键约束与 Factory。实现 append-only 不可变保护：PageVersion 创建后不允许修改或删除，CopyRevision 创建后不可变。实现细节见 `DEV-006B_IMPLEMENTATION.md`。

## DEV-007A / 007A.1：Page / Revision API 与 confirmed 唯一入口（已完成并合入 main）

实现 ContentPage 的列表、创建、排序、版本追加 API；实现 ContentCopyRevision 的列表 API；实现 `POST .../copy/confirm` 正式确认入口——确认时创建新 Revision + 完整 PageVersion 正式快照。普通 ContentItem API 不允许直接进入 confirmed。契约见 `DEV-007A_PAGE_API_CONTRACT.md`。

## DEV-W04 / W04.1：ContentPage 文案编辑 UI 与 ProjectContext 修正（已完成并合入 main）

实现 ContentPage 文案编辑界面（Working Copy 草稿编辑），接入 Page Version 追加 API；修正 ProjectContext 在文案编辑场景下的作用域问题。

## DEV-D06 / D06.1 / D06.2：37 页历史正式文案 ContentPage 精确映射与 Unicode 保真（已完成）

完成青柠育见 4 篇 / 37 页历史内容到正式 ContentPage Schema 的逐页映射审计。以各栏目 `最终上图文案.md` 为逐页正式文案权威源，旧 Fixture 仅作 legacy ID 辅助。完成 Unicode 标点保真校正（中文双引号 U+201C/U+201D）。映射文档见 `DEV-D06_CONTENT_PAGE_MAPPING.md`。本轮为纯文档/审计任务，不创建 Importer / Migration / Model。

## DEV-D07 / D07.1：Source Provenance 实源审计 + 正式架构文档基线同步（已完成并合入 main）

完成历史来源文件（逐页脚本、内容台账、收尾句台账、导航索引）的实源审计，明确每个 source role 的 scope / authority / verification_status。同步更新 ARCHITECTURE / DATABASE_BASELINE / DEV_TASKS 三份正式文档，反映 ContentPage 与版本模型已正式实现的当前事实。

V1.1 校正：authority 语义正式收敛为四类（authoritative / evidence / index / reference）；确认当前不需要 `content_column_id`；修正 Working Copy 定义。审计文档见 `DEV-D07_SOURCE_PROVENANCE_AUDIT.md`。本轮为纯文档任务，不创建 Migration / Model / SourceReference 表 / Importer。

## DEV-008A：SourceReference Lite 核心数据层（已完成并合入 main）

新增 `source_references` Migration、SourceRole / SourceAuthority Enum、Model 关系、Factory 和数据库测试。Project 级引用允许空 `content_item_id`；Item 级引用由复合外键禁止跨 Project。来源路径可被多个 Item 共用，同一 Item 可引用多个脚本版本。实现说明见 `DEV-008A_SOURCE_REFERENCE_IMPLEMENTATION.md`。本轮不实现 API、UI、Importer 或 Markdown Parser。

## DEV-008B：青柠育见 4 篇 / 37 页正式历史 Importer（已完成并合入 main）

实现必填 `--source-root` 的 Artisan 命令；默认只预检，`--apply` 才在整批事务内写入。直接读取四个 `最终上图文案.md`，以 DEV-D06 作为独立验收对照，导入 1 Project、6 Column、4 Topic、4 Item、37 Page、4 历史 Revision、37 正式 PageVersion 和 11 SourceReference。重复执行核对历史基线后 no-op；冲突即停止。不创建生产任务或渠道任务。详见 `DEV-008B_YUJIAN_HISTORY_IMPORTER.md`。

## DEV-009A：ProductionTask 正式文案版本绑定与服务端 API（已完成并合入 main）

新增可空 `copy_revision_id` 兼容旧任务，以复合外键限定同 Project、同 ContentItem 的正式文案 Revision。新增 GET、POST、PATCH 与显式 `use-current-copy` 服务端 API；创建必须从 confirmed 文案绑定当前正式 Revision，批准图稿需与当前 Revision 对齐，后续文案确认不自动换版。详见 `DEV-009A_PRODUCTION_TASK_API.md`。本轮不实现 ChannelTask API、前端 UI 或资产系统。

## DEV-W05 / W05.1：ChannelTask 渠道流程 API 与发布排期（已完成并合入 main）

在既有 ChannelTask 骨架上实现微信公众号 / 微信视频号的真实服务端流程：新增可空 `scheduled_at`、`published_at` 两个时间列（additive 迁移，既有行为 null，统一按 UTC 存储），提供渠道创建、列表、单渠道读取、视频状态推进、发布排期与实际发布记录，以及 Production stale 门禁与显式 `restart-with-current-copy` 整链 reset。四维状态严格独立，已发布是普通 Publish API 的终态、不可被普通接口或 reset 抹掉。统一行锁顺序为 ContentItem → ProductionTask → ChannelTask。本轮不调用任何微信真实 API，不做自动发布，不开发渠道专属文案、Channel / Production 前端 UI、Asset 体系与 AI 生图 / 视频。详见 `DEV-W05_CHANNEL_TASK_API.md`。

DEV-W05.1 为终审收口：修正「父 ProductionTask 不存在时创建渠道任务应返回 404」、「`published` 为普通 Publish API 终态（含 `published → scheduled` 拒绝）」与「发布请求禁止携带 `scheduled_at`」，并订正文档中的 MySQL 8.4 验证状态与文件名引用。DEV-W05 / W05.1 已通过终审并合入 main；MySQL 8.4 实机验证仍按 DATABASE_BASELINE 中的 BLOCKED 说明待后续安全测试库补验。

## DEV-W06 / W06.1：Production + Channel 统一生产工作台 UI（已完成并合入 main）

把已冻结的 DEV-009A（ProductionTask）与 DEV-W05（ChannelTask）服务端 API 接入真实后台 UI。新增 Inertia 页面 `Production/Workspace`（`/projects/{p}/columns/{c}/topics/{t}/items/{i}/production`，route name `items.production`，不与 `/api` 前缀冲突），在单页面内完成篇目/正式文案概览、创建 ProductionTask、图稿状态推进、stale 识别、`use-current-copy` 与 `restart-with-current-copy` 分流、公众号/视频号渠道创建、视频状态推进、发布排期与正式发布。新增 `productionTasks.ts` / `channelTasks.ts` adapter、严格联合状态类型与中文标签、`ConfirmDialog.vue`，并给 `Badge` 增加 `danger` 变体；`ContentItems/Index` 增加「制作与渠道」入口；`AdminLayout` 的「规划中」分组订正为「内容流程」。

前端一律采用服务端返回的 `is_copy_revision_current` / `is_production_copy_current` 判断版本新旧，不用 `copy_status` 推断；`published` 作为发布终态不提供任何回退入口；标记已发布不回传 `scheduled_at`（DEV-W05.1 契约）；`datetime-local` 提交前统一转 UTC ISO，UTC 返回值按浏览器本地时区显示。本轮不修改后端状态机、不新增 Migration、不开发 Asset、不调用真实微信 API。详见 `DEV-W06_PRODUCTION_CHANNEL_UI.md`。

DEV-W06.1 为终审收口：拆分 `handleLoadError` / `handleActionError`，使业务门禁 422 只弹 Toast 而不再把工作台替换成 ErrorState；补齐图稿审核通过与 `use-current-copy` 的前端门禁镜像（均要求文案已确认且 Production current）；实际发布时间非法输入明确阻止发布。DEV-W06 / W06.1 已通过终审并合入 main；浏览器级 Smoke 仍按 `DEV-W06_PRODUCTION_CHANNEL_UI.md` 中的 `BROWSER_SMOKE_BLOCKED` 说明待后续安全测试环境补验。

## 后续待拆分

以下能力尚未实现，需分别规划独立任务：

- **共享视觉资产**：Asset / AssetVersion / File 表与 ProductionTask 关联
- **查重**：跨栏目收尾句 / 文案查重能力
- **Production / Channel 前端 UI**：已由 DEV-W06 实现服务端 API 接线；跨篇目的生产看板与统计仍待规划
- **渠道专属文案**：渠道适配文案建模（DEV-W05 / DEV-W06 只做状态与排期，不建模渠道文案）
- **微信平台真实发布**：access_token 与公众号 / 视频号真实发布接口（DEV-W05 只记录内部工作台状态）
- **用户认证与权限**：多用户、成员关系、Project 访问授权
