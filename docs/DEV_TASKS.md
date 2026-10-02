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

## DEV-D07：Source Provenance 实源审计 + 正式架构文档基线同步（本分支）

完成历史来源文件（逐页脚本、内容台账、收尾句台账、导航索引）的实源审计，明确每个 source role 的 scope / authority / verification_status。同步更新 ARCHITECTURE / DATABASE_BASELINE / DEV_TASKS 三份正式文档，反映 ContentPage 与版本模型已正式实现的当前事实。审计文档见 `DEV-D07_SOURCE_PROVENANCE_AUDIT.md`。本轮为纯文档任务，不创建 Migration / Model / SourceReference 表 / Importer。

## 后续待拆分

以下能力尚未实现，需分别规划独立任务：

- **Source Reference**：历史来源文件的数据库引用建模（project_id / content_item_id / role / authority / source_path）
- **历史内容 Importer**：将 37 页历史正式文案导入正式数据库
- **共享视觉资产**：Asset / AssetVersion / File 表与 ProductionTask 关联
- **查重**：跨栏目收尾句 / 文案查重能力
- **渠道生产 API**：视频状态推进、发布排期等
- **用户认证与权限**：多用户、成员关系、Project 访问授权