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

## DEV-004：四维状态体系正式收口（已完成）

新增 Copy、Artwork、Video、Publish 四个 string backed Enum；用独立 correction migration 回填旧状态并更新数据库默认值，保持 DEV-002 历史迁移不变。更新 Model cast、Factory、领域测试及架构文档。此任务不开发 Topic / ContentItem API 或 UI、ContentPage、资产、导入器及生产流程。

## DEV-005：Topic / ContentItem 服务端业务与 API（已完成）

基于正式 Main 的独立工作区，实现 Topic 与 ContentItem 的列表、创建、读取和编辑；沿用 Session ProjectContext，逐层验证 Project / ContentColumn / Topic / ContentItem 的归属，拒绝客户端伪造归属键。ContentItem 使用 DEV-004 的 CopyStatus，创建时不自动创建 ProductionTask。契约见 DEV-005_API_CONTRACT.md。本轮不开发 UI、ContentPage、Importer 或生产/渠道 API。已完成服务端 API、作用域校验、Feature 测试与数据契约；前端 UI 留给后续独立任务。

## 后续待拆分

Topic / ContentItem 界面、逐页内容与版本、共享视觉资产、查重与发布流程仍需分别规划。每项先补充数据归属与验收标准，再分配独立分支；不得由已完成任务顺手实现。
